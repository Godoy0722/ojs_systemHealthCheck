<?php

/**
 * @file tools/settingsHealthCheck.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SettingsHealthCheckTool
 *
 * @ingroup tools
 *
 * @brief CLI settings health check. Read-only by default; --fix writes to the DB.
 */

require(dirname(__FILE__) . '/../bootstrap.inc.php');

require_once(dirname(__FILE__) . '/src/Finding.php');
require_once(dirname(__FILE__) . '/src/SettingsFkRegistry.php');
require_once(dirname(__FILE__) . '/src/IlluminateDatabaseGateway.php');
require_once(dirname(__FILE__) . '/src/SchemaRegistry.php');
require_once(dirname(__FILE__) . '/src/JournalCascadeRegistry.php');
require_once(dirname(__FILE__) . '/src/Scanner.php');
require_once(dirname(__FILE__) . '/src/ReportWriter.php');
require_once(dirname(__FILE__) . '/src/EntityReferenceRule.php');
require_once(dirname(__FILE__) . '/src/EntityReferenceRegistry.php');
require_once(dirname(__FILE__) . '/src/OrphanReferenceCleaner.php');
require_once(dirname(__FILE__) . '/src/Fixer.php');

use APP\tools\settingsHealthCheck\src\Finding;
use APP\tools\settingsHealthCheck\src\Fixer;
use APP\tools\settingsHealthCheck\src\IlluminateDatabaseGateway;
use APP\tools\settingsHealthCheck\src\JournalCascadeRegistry;
use APP\tools\settingsHealthCheck\src\ReportWriter;
use APP\tools\settingsHealthCheck\src\Scanner;
use APP\tools\settingsHealthCheck\src\SchemaRegistry;

class SettingsHealthCheckTool extends CommandLineTool
{
    /** @var string[] */
    private $checks = [];

    /** @var bool */
    private $fix = false;

    public function __construct(array $argv = [])
    {
        parent::__construct($argv);

        $args = array_slice($argv, 1);
        if (empty($args)) {
            $this->usage();
            exit(0);
        }

        $selected = $this->argumentWrapper($args);
        $this->checks = array_keys($selected);
        if (empty($this->checks) && !$this->fix) {
            $this->usage();
            exit(0);
        }
    }

    public function usage(): void
    {
        echo <<<EOT
            Usage: php tools/settingsHealthCheck.php <check> [--fix]

            Checks:
            -o, --orphan           Orphaned settings, entity FK refs, and unreferenced blob files
            -l, --locale           Bad locale tags on multilingual settings
            -e, --empty            Required NULL columns and NULL setting_value
            -r, --review           REVIEW_REVISION files
            -d, --deleted-journal  Deleted journal leftovers
            -a, --all              All checks above
            -h, --help             This message

            -f, --fix              Enable fix mode; press [f] in the menu to apply.
                                   Re-scans and re-fixes until no fixable rows remain.

        EOT;
    }

    private const MAX_FIX_PASSES = 25;

    public function execute(): void
    {
        $exitCode = 0;
        try {
            $gateway = new IlluminateDatabaseGateway();

            $ojsRoot = dirname(INDEX_FILE_LOCATION);
            $libPkpSchemaDir = $ojsRoot . '/lib/pkp/schemas';
            $schemaDir = $ojsRoot . '/schemas';
            $registry = new SchemaRegistry($libPkpSchemaDir, $schemaDir);

            $schemaMap = $registry->build();
            $entityMap = $registry->buildEntities();

            foreach ($registry->getWarnings() as $w) {
                fwrite(STDERR, ReportWriter::color("[WARN]", 'bold|yellow') . " {$w}\n");
            }

            $cascadeRegistry = new JournalCascadeRegistry($gateway);
            $scanner = new Scanner($gateway, $cascadeRegistry);
            $writer = new ReportWriter();

            $scanner->initialize($schemaMap, $entityMap);
            $allFindings = $scanner->scan($this->checks);
            $stats = $writer->computeStats($allFindings);

            foreach ($scanner->getWarnings() as $w) {
                fwrite(STDERR, ReportWriter::color("[WARN]", 'bold|yellow') . " {$w}\n");
            }

            $context = $scanner->getContextStats();
            $context['checks'] = $this->checks;
            $context['tableResults'] = $scanner->getTableResults();
            $context['entityResults'] = $scanner->getEntityResults();
            $context['findings'] = $allFindings;

            $applyFix = $writer->renderInteractive($context, $this->fix);

            if ($applyFix) {
                $this->confirmDestructiveFixes($allFindings);

                $totals = [
                    'orphansDeleted' => 0,
                    'orphanFilesDeleted' => 0,
                    'entityReferencesRecovered' => 0,
                    'entityOrphansFixed' => 0,
                    'localesFixed' => 0,
                    'reviewFilesDeleted' => 0,
                    'journalRecordsDeleted' => 0,
                ];
                $findings = $allFindings;
                $pass = 0;
                $lastPassResult = null;

                while ($pass < self::MAX_FIX_PASSES) {
                    $fixableBefore = $this->countFixableRows($findings);
                    if ($fixableBefore === 0) {
                        break;
                    }

                    $pass++;
                    if ($pass > 1) {
                        echo ReportWriter::color(
                            "\n  Fix pass {$pass} ({$fixableBefore} fixable records remaining)...\n",
                            'bold|cyan'
                        );
                    }

                    $fixer = new Fixer($gateway, $cascadeRegistry);
                    $fixResult = $fixer->fix($findings);
                    $lastPassResult = $fixResult;
                    $this->mergeFixSuccessTotals($totals, $fixResult);
                    foreach ($fixer->getWarnings() as $w) {
                        fwrite(STDERR, ReportWriter::color("[WARN]", 'bold|yellow') . " {$w}\n");
                    }

                    $recheck = $this->checks;
                    if ($fixResult['journalRecordsDeleted'] > 0) {
                        $recheck = array_values(array_filter(
                            $this->checks,
                            function ($check) {
                                return $check !== Scanner::CHECK_JOURNAL;
                            }
                        ));
                    }
                    $findings = $scanner->scan($recheck);
                    foreach ($scanner->getWarnings() as $w) {
                        fwrite(STDERR, ReportWriter::color("[WARN]", 'bold|yellow') . " {$w}\n");
                    }

                    if ($this->countFixableRows($findings) === 0) {
                        break;
                    }
                    if (!$this->fixMadeProgress($fixResult)) {
                        fwrite(STDERR, ReportWriter::color(
                            "[WARN] Fix pass {$pass} made no progress; stopping with "
                            . $this->countFixableRows($findings) . " fixable records left.\n",
                            'bold|yellow'
                        ));
                        break;
                    }
                    if ($pass >= 2 && ($fixResult['orphansDeleted'] + $fixResult['localesFixed']
                        + $fixResult['entityOrphansFixed'] + $fixResult['reviewFilesDeleted']) === 0) {
                        break;
                    }
                }

                if ($pass >= self::MAX_FIX_PASSES && $this->countFixableRows($findings) > 0) {
                    fwrite(STDERR, ReportWriter::color(
                        '[WARN] Reached maximum fix passes (' . self::MAX_FIX_PASSES . "); some fixable records remain.\n",
                        'bold|yellow'
                    ));
                }

                $stats = $writer->computeStats($findings);
                $remainingFixable = $this->countFixableRows($findings);
                echo $this->renderFixSummary($totals, $pass, $remainingFixable, $lastPassResult, $findings);
            }

            $exitCode = $stats > 0 ? 1 : 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, ReportWriter::color("[ERROR]", 'bold|red') . " {$e->getMessage()}\n");
            $exitCode = 2;
        }
        exit($exitCode);
    }

	/** @param string[] $args @return array<string,bool> */
	private function argumentWrapper(array $args): array
	{
		$selected = [];
        foreach ($args as $arg) {
            switch ($arg) {
                case '-h':
                case '--help':
                    $this->usage();
                    exit(0);
                case '-o':
                case '--orphan':
                    $selected[Scanner::CHECK_ORPHAN] = true;
                    break;
                case '-l':
                case '--locale':
                    $selected[Scanner::CHECK_LOCALE] = true;
                    break;
                case '-e':
                case '--empty':
                    $selected[Scanner::CHECK_EMPTY] = true;
                    break;
                case '-r':
                case '--review':
                    $selected[Scanner::CHECK_REVIEW] = true;
                    break;
                case '-d':
                case '--deleted-journal':
                    $selected[Scanner::CHECK_JOURNAL] = true;
                    break;
                case '-a':
                case '--all':
                    $selected[Scanner::CHECK_LOCALE] = true;
                    $selected[Scanner::CHECK_ORPHAN] = true;
                    $selected[Scanner::CHECK_EMPTY] = true;
                    $selected[Scanner::CHECK_REVIEW] = true;
                    $selected[Scanner::CHECK_JOURNAL] = true;
                    break;
                case '-f':
                case '--fix':
                    $this->fix = true;
                    break;
                default:
                    fwrite(STDERR, ReportWriter::color("[ERROR]", 'bold|red') . " Unknown argument: {$arg}\n");
                    $this->usage();
                    exit(2);
            }
        }

		return $selected;
	}

    /** @param Finding[] $findings */
    private function confirmDestructiveFixes(array $findings): void
    {
        $counts = ['review' => 0, 'journalRows' => 0, 'journalIds' => [], 'journalIdCount' => 0, 'orphanFiles' => 0, 'entityOrphans' => 0];
        foreach ($findings as $f) {
            switch ($f->reason) {
                case Finding::REASON_REVIEW_REVISION:
                    $counts['review'] += $f->rowCount;
                    break;
                case Finding::REASON_DELETED_JOURNAL:
                    $counts['journalRows'] += $f->rowCount;
                    if ($f->entityId !== null) {
                        $counts['journalIds'][(int) $f->entityId] = true;
                    } elseif (preg_match('/^(\d+) dead journal/', $f->valuePreview, $m)) {
                        $counts['journalIdCount'] = max($counts['journalIdCount'], (int) $m[1]);
                    }
                    break;
                case Finding::REASON_ORPHAN_ENTITY:
                    if ($f->table === 'files') {
                        $counts['orphanFiles'] += $f->rowCount;
                    } elseif (Finding::isEntityOrphan($f)) {
                        $counts['entityOrphans'] += $f->rowCount;
                    }
                    break;
            }
        }

        foreach ([
            [$counts['review'], [
                "WARNING: {$counts['review']} file(s) under REVIEW_REVISION.",
                'Fixing will permanently delete these files and their database records.',
            ]],
            [$counts['journalRows'], [
                'WARNING: ' . $counts['journalRows'] . ' row(s) belonging to '
                    . (count($counts['journalIds']) ?: $counts['journalIdCount']) . ' deleted journal(s).',
                'Fixing will permanently delete every one of those rows.',
            ]],
            [$counts['orphanFiles'], [
                "WARNING: {$counts['orphanFiles']} unreferenced blob file(s) in the files table.",
                'Fixing will delete those files from disk and the database.',
            ]],
            [$counts['entityOrphans'], [
                "WARNING: {$counts['entityOrphans']} entity row(s) with invalid references in live journals.",
                'Rows will be DELETED or SET NULL after repointing current_publication_id/section_id.',
            ]],
        ] as [$n, $lines]) {
            if ($n > 0) {
                $this->confirmDestructiveFix($lines);
            }
        }
    }

    /** @param Finding[] $findings */
    private function countFixableRows(array $findings): int
    {
        $total = 0;
        foreach ($findings as $f) {
            if ($this->isFixableFinding($f)) {
                $total += $f->rowCount;
            }
        }
        return $total;
    }

    private function isFixableFinding(Finding $f): bool
    {
        switch ($f->reason) {
            case Finding::REASON_ORPHAN_ENTITY:
            case Finding::REASON_SCHEMA_MISSING_LOCALE:
            case Finding::REASON_HEURISTIC_LOCALE_MISMATCH:
            case Finding::REASON_REVIEW_REVISION:
            case Finding::REASON_DELETED_JOURNAL:
                return true;
            default:
                return false;
        }
    }

    /** @param array{orphansDeleted:int, orphanFilesDeleted:int, entityReferencesRecovered:int, entityOrphansFixed:int, localesFixed:int, reviewFilesDeleted:int, journalRecordsDeleted:int, alreadyRemoved:int, skipped:int, failed:int} $result */
    private function fixMadeProgress(array $result): bool
    {
        return ($result['orphansDeleted'] + $result['orphanFilesDeleted'] + $result['entityReferencesRecovered']
            + $result['entityOrphansFixed'] + $result['localesFixed'] + $result['reviewFilesDeleted']
            + $result['journalRecordsDeleted'] + $result['alreadyRemoved']) > 0;
    }

    /**
     * @param array{orphansDeleted:int, orphanFilesDeleted:int, entityReferencesRecovered:int, entityOrphansFixed:int, localesFixed:int, reviewFilesDeleted:int, journalRecordsDeleted:int, alreadyRemoved:int, skipped:int, failed:int} $totals
     * @param array{orphansDeleted:int, orphanFilesDeleted:int, entityReferencesRecovered:int, entityOrphansFixed:int, localesFixed:int, reviewFilesDeleted:int, journalRecordsDeleted:int, alreadyRemoved:int, skipped:int, failed:int} $pass
     */
    private function mergeFixSuccessTotals(array &$totals, array $pass): void
    {
        foreach (['orphansDeleted', 'orphanFilesDeleted', 'entityReferencesRecovered', 'entityOrphansFixed',
            'localesFixed', 'reviewFilesDeleted', 'journalRecordsDeleted'] as $key) {
            $totals[$key] += $pass[$key];
        }
    }

    /** @param Finding[] $findings */
    private function countNonFixableRows(array $findings): int
    {
        $total = 0;
        foreach ($findings as $f) {
            if (!$this->isFixableFinding($f)) {
                $total += $f->rowCount;
            }
        }
        return $total;
    }

    /**
     * @param array{orphansDeleted:int, orphanFilesDeleted:int, entityReferencesRecovered:int, entityOrphansFixed:int, localesFixed:int, reviewFilesDeleted:int, journalRecordsDeleted:int, alreadyRemoved:int, skipped:int, failed:int} $totals
     * @param array{orphansDeleted:int, orphanFilesDeleted:int, entityReferencesRecovered:int, entityOrphansFixed:int, localesFixed:int, reviewFilesDeleted:int, journalRecordsDeleted:int, alreadyRemoved:int, skipped:int, failed:int}|null $lastPass
     * @param Finding[] $finalFindings
     */
    private function renderFixSummary(array $totals, int $passes, int $remainingFixable, ?array $lastPass, array $finalFindings): string
    {
        $c = fn(string $t, string $clr) => ReportWriter::color($t, $clr);
        $failed = $remainingFixable === 0 ? 0 : (int) ($lastPass['failed'] ?? 0);
        $alreadyRemoved = $remainingFixable === 0 ? 0 : (int) ($lastPass['alreadyRemoved'] ?? 0);
        $skipped = $this->countNonFixableRows($finalFindings);

        $lines = [];
        $lines[] = '';
        $lines[] = '  ' . $c('Fixes applied', 'bold');
        $lines[] = '  ' . $c('-------------', 'bold');
        if ($passes > 1) {
            $lines[] = sprintf('  Fix passes run        : %s', $c((string) $passes, 'cyan'));
        }
        if ($totals['entityReferencesRecovered'] > 0) {
            $lines[] = sprintf('  References recovered  : %s', $c((string) $totals['entityReferencesRecovered'], 'green'));
        }
        $lines[] = sprintf('  Orphaned rows deleted : %s', $c((string) $totals['orphansDeleted'], 'green'));
        $lines[] = sprintf('  Orphan blob files del : %s', $c((string) $totals['orphanFilesDeleted'], 'green'));
        $lines[] = sprintf('  Entity orphans fixed  : %s', $c((string) $totals['entityOrphansFixed'], 'green'));
        $lines[] = sprintf('  Missing locales set   : %s', $c((string) $totals['localesFixed'], 'green'));
        $lines[] = sprintf('  Review files deleted  : %s', $c((string) $totals['reviewFilesDeleted'], 'green'));
        $lines[] = sprintf('  Journal rows deleted  : %s', $c((string) $totals['journalRecordsDeleted'], 'green'));
        $lines[] = sprintf('  Empty fields skipped  : %s  (no auto-fix yet)', $c((string) $skipped, 'yellow'));
        if ($alreadyRemoved > 0) {
            $lines[] = sprintf('  Already removed       : %s  (deleted by the journal cascade)', $c((string) $alreadyRemoved, 'dim'));
        }
        if ($failed > 0) {
            $lines[] = sprintf('  Failed                : %s  (see warnings above)', $c((string) $failed, 'red'));
        }
        if ($remainingFixable > 0) {
            $lines[] = sprintf(
                '  Fixable remaining     : %s  (blocked or could not progress)',
                $c((string) $remainingFixable, 'yellow')
            );
        } elseif ($passes > 0) {
            $lines[] = '  ' . $c('All fixable records resolved.', 'green');
        }
        return implode("\n", $lines) . "\n";
    }

    /** @param string[] $warningLines */
    private function confirmDestructiveFix(array $warningLines): void
    {
        if (!(function_exists('stream_isatty') && stream_isatty(STDIN))) {
            fwrite(STDERR, ReportWriter::color("[ERROR]", 'bold|red') . " Refusing --fix with piped input. Run interactively with a real terminal.\n");
            exit(2);
        }

        echo "\n";
        echo ReportWriter::color("================================================================================\n", 'bold|red');
        foreach ($warningLines as $line) {
            echo ReportWriter::color($line . "\n", 'bold|red');
        }
        echo ReportWriter::color("================================================================================\n\n", 'bold|red');

        echo "Stage 1/3: Are you aware that this operation will delete data in the database? (yes/no): ";
        if (strtolower(trim(fgets(STDIN))) !== 'yes') {
            echo ReportWriter::color("Aborted: User did not confirm awareness of database deletion.\n", 'yellow');
            exit(1);
        }

        echo "Stage 2/3: Do you really want to execute this operation in the database? This is your second confirmation. (yes/no): ";
        if (strtolower(trim(fgets(STDIN))) !== 'yes') {
            echo ReportWriter::color("Aborted: User did not provide the second confirmation.\n", 'yellow');
            exit(1);
        }

        echo "Stage 3/3: This is the final confirmation. This will permanently delete files and database records. Confirm by typing 'DELETE': ";
        if (trim(fgets(STDIN)) !== 'DELETE') {
            echo ReportWriter::color("Aborted: Final confirmation mismatch.\n", 'yellow');
            exit(1);
        }

        echo ReportWriter::color("\nConfirmation successful. Moving forward with the execution...\n\n", 'green');
    }

}

$tool = new SettingsHealthCheckTool(isset($argv) ? $argv : []);
$tool->execute();
