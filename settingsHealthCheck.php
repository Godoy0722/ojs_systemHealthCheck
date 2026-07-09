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
 * @brief CLI diagnostic that scans every *_settings table for rows storing a
 *        multilingual field with an empty/null locale (PHP 8 hydration breaker).
 *        Read-only by default (prints stdout summary); with
 *        --fix it also applies basic remediations to the database.
 *        See REPORT.md for the host05/ajis.aaisnet.org incident that motivated this.
 *
 *        OJS 3.3 / PHP 7.4 port: uses the global Config/CommandLineTool classes,
 *        the Illuminate Capsule manager (the DB facade has no root in 3.3), and
 *        manual require_once for the tool classes (no APP\ autoloader in 3.3).
 */

require(dirname(__FILE__) . '/../bootstrap.inc.php');

require_once(dirname(__FILE__) . '/src/Finding.php');
require_once(dirname(__FILE__) . '/src/IlluminateDatabaseGateway.php');
require_once(dirname(__FILE__) . '/src/SchemaRegistry.php');
require_once(dirname(__FILE__) . '/src/Scanner.php');
require_once(dirname(__FILE__) . '/src/ReportWriter.php');
require_once(dirname(__FILE__) . '/src/Fixer.php');

use APP\tools\settingsHealthCheck\src\Finding;
use APP\tools\settingsHealthCheck\src\Fixer;
use APP\tools\settingsHealthCheck\src\IlluminateDatabaseGateway;
use APP\tools\settingsHealthCheck\src\ReportWriter;
use APP\tools\settingsHealthCheck\src\Scanner;
use APP\tools\settingsHealthCheck\src\SchemaRegistry;

class SettingsHealthCheckTool extends CommandLineTool
{
    /** @var string[]
	 * Selected Scanner::CHECK_* passes to run.
	 */
    private $checks = [];

    /** @var bool
	 * Whether to apply fixes for the findings (mutates the DB).
	 */
    private $fix = false;

    /**
     * Parses CLI arguments and selects which Scanner checks to run.
     * With no arguments, prints usage and exits.
     */
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
    }

    /**
     * Prints the CLI help text listing every check flag, fix flag, and usage examples.
     */
    public function usage(): void
    {
        echo <<<EOT
            Usage: php tools/settingsHealthCheck.php <check>

            Read-only diagnostic over every *_settings table. Pick one or more checks;
            running with no check shows this message. Prints a summary to stdout.

            Checks:
            -o, --orphan    Only orphaned settings (parent entity no longer exists)
            -l, --locale    Only missing translations (schema-driven + heuristic guess)
            -e, --empty     Only empty fields (required NULL columns + NULL setting_value)
            -r, --review    Only files under REVIEW_REVISION status (causes deleteJournal error)
            -a, --all       Run every check above (the full scan)
            -h, --help      Show this message

            Checks combine, e.g. `--orphan --empty` runs both.

            Fix:
            -f, --fix       Apply basic fixes to the findings (WRITES to the DB):
                            orphaned rows are deleted, missing-locale rows are
                            stamped with the default locale. Empty-field findings
                            are reported but never auto-fixed. Files under REVIEW_REVISION
                            findings are physically deleted along with their database rows,
                            after multiple confirmation prompts. Combine with a check,
                            e.g. `--review --fix`.

        EOT;
    }

    /**
     * Main entry point. Builds the schema registry, initialises the scanner,
     * runs the selected checks, prints the summary, and optionally applies
     * fixes (with review-revision confirmation when needed). Exits 0 (clean),
     * 1 (findings), or 2 (error).
     */
    public function execute(): void
    {
        $exitCode = 0;
        try {
			$libPkpSchemaDir = dirname(__FILE__, 2) . '/lib/pkp/schemas';
			$schemaDir = dirname(__FILE__, 2) . '/schemas';
            $registry = new SchemaRegistry($libPkpSchemaDir, $schemaDir);

            $schemaMap = $registry->build();
            $entityMap = $registry->buildEntities();

            foreach ($registry->getWarnings() as $w) {
                fwrite(STDERR, "[WARN] {$w}\n");
            }

            $gateway = new IlluminateDatabaseGateway();
            $scanner = new Scanner($gateway);
            $writer = new ReportWriter();

            $scanner->initialize($schemaMap, $entityMap);
            $allFindings = $scanner->scan($this->checks);
            $stats = $writer->computeStats($allFindings);

            foreach ($scanner->getWarnings() as $w) {
                fwrite(STDERR, "[WARN] {$w}\n");
            }

            $context = $scanner->getContextStats();
            $context['checks'] = $this->checks;
            $context['tableResults'] = $scanner->getTableResults();
            $context['entityResults'] = $scanner->getEntityResults();
            $context['findings'] = $allFindings;

            echo $writer->renderSummary($context);

            if ($this->fix) {
                $reviewFindingsCount = 0;
                foreach ($allFindings as $f) {
                    if ($f->reason === Finding::REASON_REVIEW_REVISION) {
                        $reviewFindingsCount++;
                    }
                }

                if ($reviewFindingsCount > 0) {
                    $this->confirmReviewFix($reviewFindingsCount);
                }

                $fixer = new Fixer($gateway);
                $fixResult = $fixer->fix($allFindings);
                foreach ($fixer->getWarnings() as $w) {
                    fwrite(STDERR, "[WARN] {$w}\n");
                }
                echo $this->renderFixSummary($fixResult);
            }

            $exitCode = $stats > 0 ? 1 : 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, "[ERROR] {$e->getMessage()}\n");
            $exitCode = 2;
        }
        exit($exitCode);
    }

	/**
	 * @param string[] $args
	 * @return array<string,bool>
	 *
	 * This method processes command-line arguments and return the selected checks as an associative array.
	 */
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
                case '-a':
                case '--all':
                    $selected[Scanner::CHECK_LOCALE] = true;
                    $selected[Scanner::CHECK_ORPHAN] = true;
                    $selected[Scanner::CHECK_EMPTY] = true;
                    $selected[Scanner::CHECK_REVIEW] = true;
                    break;
                case '-f':
                case '--fix':
                    $this->fix = true;
                    break;
                default:
                    fwrite(STDERR, "Unknown argument: {$arg}\n");
                    $this->usage();
                    exit(2);
            }
        }

		return $selected;
	}

    /**
     * Three-stage interactive confirmation before deleting review-revision
     * files. Exits the process immediately if any stage is declined.
     *
     * @param int $count Number of REVIEW_REVISION files about to be deleted
     */
    private function confirmReviewFix(int $count): void
    {
        echo "\n";
        echo "================================================================================\n";
        echo "WARNING: The scan found {$count} file(s) under the REVIEW_REVISION status.\n";
        echo "Fixing these findings will permanently delete these files and their database records.\n";
        echo "================================================================================\n\n";

        echo "Stage 1/3: Are you aware that this operation will delete data in the database? (yes/no): ";
        if (strtolower(trim(fgets(STDIN))) !== 'yes') {
            echo "Aborted: User did not confirm awareness of database deletion.\n";
            exit(1);
        }

        echo "Stage 2/3: Do you really want to execute this operation in the database? This is your second confirmation. (yes/no): ";
        if (strtolower(trim(fgets(STDIN))) !== 'yes') {
            echo "Aborted: User did not provide the second confirmation.\n";
            exit(1);
        }

        echo "Stage 3/3: This is the final confirmation. This will permanently delete files and database records. Confirm by typing 'DELETE': ";
        if (trim(fgets(STDIN)) !== 'DELETE') {
            echo "Aborted: Final confirmation mismatch.\n";
            exit(1);
        }

        echo "\nConfirmation successful. Moving forward with the execution...\n\n";
    }

    /**
     * Formats the fix result counters as a compact text block shown after --fix.
     *
     * @param array{orphansDeleted:int, localesFixed:int, reviewFilesDeleted:int, skipped:int, failed:int} $r
     */
    private function renderFixSummary(array $r): string
    {
        $lines = [];
        $lines[] = '';
        $lines[] = '  Fixes applied';
        $lines[] = '  -------------';
        $lines[] = sprintf('  Orphaned rows deleted : %d', $r['orphansDeleted']);
        $lines[] = sprintf('  Missing locales set   : %d', $r['localesFixed']);
        $lines[] = sprintf('  Review files deleted  : %d', $r['reviewFilesDeleted']);
        $lines[] = sprintf('  Empty fields skipped  : %d  (no auto-fix yet)', $r['skipped']);
        if ($r['failed'] > 0) {
            $lines[] = sprintf('  Failed                : %d  (see warnings above)', $r['failed']);
        }
        return implode("\n", $lines) . "\n";
    }

}

$tool = new SettingsHealthCheckTool(isset($argv) ? $argv : []);
$tool->execute();
