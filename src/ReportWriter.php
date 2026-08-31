<?php

/**
 * @file tools/SettingsHealthCheck/ReportWriter.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReportWriter
 *
 * @brief Computes finding statistics and renders an interactive stdout report.
 *        Shows a summary table first, then lets the user drill into each
 *        scenario (reason group) to see full-row details.
 */

namespace APP\tools\settingsHealthCheck\src;

final class ReportWriter
{
    private const C_RESET  = "\033[0m";
    private const C_BOLD   = "\033[1m";
    private const C_DIM    = "\033[2m";
    private const C_RED    = "\033[31m";
    private const C_GREEN  = "\033[32m";
    private const C_YELLOW = "\033[33m";
    private const C_CYAN   = "\033[36m";
    private const C_MAGENTA = "\033[35m";

    /**
     * Wrap $text in ANSI color codes. Multiple colors combined via pipe,
     * e.g. "bold|red". Pass empty string to skip wrapping.
     */
    private static ?bool $supportsColor = null;

    public static function color(string $text, string $color): string
    {
        if ($color === '' || !self::supportsColor()) {
            return $text;
        }
        $codes = [];
        foreach (explode('|', $color) as $c) {
            $const = 'self::C_' . strtoupper($c);
            if (defined($const)) {
                $codes[] = constant($const);
            }
        }
        if (empty($codes)) {
            return $text;
        }
        return implode('', $codes) . $text . self::C_RESET;
    }

    private static function supportsColor(): bool
    {
        if (self::$supportsColor !== null) {
            return self::$supportsColor;
        }
        self::$supportsColor = function_exists('stream_isatty') && stream_isatty(STDOUT)
            && getenv('NO_COLOR') === false;
        return self::$supportsColor;
    }

    /**
     * Returns the total number of rows represented by all findings.
     *
     * @param Finding[] $findings
     * @return int
     */
    public function computeStats(array $findings): int
    {
        return $this->sumRowCounts($findings);
    }

    /**
     * Each scenario maps a menu number to one or more Finding reason codes.
     * Order here determines the numbered menu order.
     */
    private const SCENARIOS = [
        1 => [
            'label'   => 'Bad locale tags',
            'reasons' => [
                Finding::REASON_SCHEMA_MISSING_LOCALE,
                Finding::REASON_HEURISTIC_LOCALE_MISMATCH,
            ],
        ],
        2 => [
            'label'   => 'Orphaned settings, entities & files',
            'reasons' => [Finding::REASON_ORPHAN_ENTITY],
        ],
        3 => [
            'label'   => 'Required fields NULL',
            'reasons' => [Finding::REASON_REQUIRED_NULL],
        ],
        4 => [
            'label'   => 'NULL setting_value',
            'reasons' => [Finding::REASON_SETTING_VALUE_NULL],
        ],
        5 => [
            'label'   => 'REVIEW_REVISION files',
            'reasons' => [Finding::REASON_REVIEW_REVISION],
        ],
        6 => [
            'label'   => 'Deleted journal leftovers',
            'reasons' => [Finding::REASON_DELETED_JOURNAL],
        ],
    ];

    /**
     * Prints the interactive report: summary table then a drill-down loop.
     * When STDIN is not a TTY (piped input), prints the summary table only
     * and exits without entering the interactive loop.
     *
     * @param array{findings?:Finding[],tableResults?:array<string,array{orphanFk?:?string}>,entityResults?:array<string,array{pk?:?string}>} $context
     * @return bool True when the user chose to apply fixes (only possible with $fixEnabled)
     */
    public function renderInteractive(array $context, bool $fixEnabled = false): bool
    {
        $findings = $context['findings'] ?? [];

        if (empty($findings)) {
            echo "\n  " . self::color('No findings — database looks clean.', 'green') . "\n\n";
            return false;
        }

        $tableResults = $context['tableResults'] ?? [];
        $entityResults = $context['entityResults'] ?? [];

        $buckets = $this->buildBuckets($findings);
        $this->renderSummaryTable($buckets, $this->sumRowCounts($findings));

        if ($fixEnabled) {
            echo "\n  " . self::color('--fix: press [f] in the menu to apply fixes, or [q] to exit without changes.', 'yellow') . "\n";
        }

        if (!(function_exists('stream_isatty') && stream_isatty(STDIN))) {
            echo "\n";
            return false;
        }

        return $this->interactiveLoop($buckets, $tableResults, $entityResults, $fixEnabled);
    }

    /**
     * Groups findings by scenario number. Each bucket is
     * ['findings' => Finding[], 'tables' => array<string,int>].
     *
     * @param Finding[] $findings
     * @return array<int, array{findings:Finding[],tables:array<string,int>}>
     */
    private function buildBuckets(array $findings): array
    {
        $buckets = [];
        foreach (array_keys(self::SCENARIOS) as $n) {
            $buckets[$n] = ['findings' => [], 'tables' => []];
        }
        foreach ($findings as $f) {
            $scenario = $this->reasonToScenario($f->reason);
            if ($scenario === null) {
                continue;
            }
            $buckets[$scenario]['findings'][] = $f;
            $table = $f->table;
            $buckets[$scenario]['tables'][$table] = ($buckets[$scenario]['tables'][$table] ?? 0) + $f->rowCount;
        }
        return $buckets;
    }

    /**
     * Sums rowCount across all findings.
     *
     * @param Finding[] $findings
     */
    private function sumRowCounts(array $findings): int
    {
        $total = 0;
        foreach ($findings as $finding) {
            $total += $finding->rowCount;
        }
        return $total;
    }

    /**
     * Sums rowCount for one scenario bucket.
     *
     * @param array{findings:Finding[]} $bucket
     */
    private function bucketRecordCount(array $bucket): int
    {
        return $this->sumRowCounts($bucket['findings']);
    }

    /**
     * Maps a reason constant to its scenario number.
     */
    private function reasonToScenario(string $reason): ?int
    {
        foreach (self::SCENARIOS as $n => $sc) {
            if (in_array($reason, $sc['reasons'], true)) {
                return $n;
            }
        }
        return null;
    }

    /**
     * Prints the compact summary table with per-scenario counts.
     *
     * @param array<int, array{findings:Finding[],tables:array<string,int>}> $buckets
     * @param int $total Total findings across all scenarios
     */
    private function renderSummaryTable(array $buckets, int $total): void
    {
        $c = fn(string $t, string $clr) => self::color($t, $clr);

        $scenariosWithFindings = 0;
        foreach ($buckets as $b) {
            if (count($b['findings']) > 0) {
                $scenariosWithFindings++;
            }
        }

        $wLabel  = 38;
        $wTables = 8;
        $wRecs   = 8;

        $sepTop    = '┌' . str_repeat('─', $wLabel + $wTables + $wRecs + 10) . '┐';
        $sepHead   = '├' . str_repeat('─', $wLabel + $wTables + $wRecs + 10) . '┤';
        $sepBottom = '└' . str_repeat('─', $wLabel + $wTables + $wRecs + 10) . '┘';

        echo "\n";
        echo $c($sepTop, 'cyan') . "\n";
        $title = 'Settings Health Check — Scan Results';
        $pad = (int)((mb_strlen($sepTop) - 2 - mb_strlen($title)) / 2);
        echo $c('│' . str_repeat(' ', $pad) . $title . str_repeat(' ', mb_strlen($sepTop) - 2 - $pad - mb_strlen($title)) . '│', 'bold|cyan') . "\n";
        echo $c($sepHead, 'cyan') . "\n";

        $hNum  = '  #';
        $hScen = '  Scenario';
        $hTab  = 'Tables';
        $hRec  = 'Records';
        echo $c(
            '│ ' . str_pad($hNum, 3) . '  ' .
            str_pad($hScen, $wLabel) . ' ' .
            str_pad($hTab, $wTables, ' ', STR_PAD_LEFT) . '  ' .
            str_pad($hRec, $wRecs, ' ', STR_PAD_LEFT) . ' │',
            'bold|cyan'
        ) . "\n";
        echo $c($sepHead, 'cyan') . "\n";

        foreach ($buckets as $n => $bucket) {
            $count  = $this->bucketRecordCount($bucket);
            $tables = count($bucket['tables']);
            $label  = self::SCENARIOS[$n]['label'];
            $dimmed = $count === 0;

            $numStr  = str_pad((string)$n, 2, ' ', STR_PAD_LEFT);
            $lblStr  = str_pad($label, $wLabel);
            $tabStr  = str_pad((string)$tables, $wTables, ' ', STR_PAD_LEFT);
            $recStr  = str_pad((string)$count, $wRecs, ' ', STR_PAD_LEFT);

            if ($dimmed) {
                echo $c("│  {$numStr}  {$lblStr} {$tabStr}  {$recStr} │", 'dim') . "\n";
            } else {
                echo $c('│', 'cyan') . "  {$numStr}  {$lblStr} " .
                    $c($tabStr, 'yellow') . '  ' .
                    $c($recStr, 'yellow') . ' ' .
                    $c('│', 'cyan') . "\n";
            }
        }

        echo $c($sepBottom, 'cyan') . "\n";
        $footer = "Total: {$total} finding" . ($total === 1 ? '' : 's') .
                   " across {$scenariosWithFindings} scenario" . ($scenariosWithFindings === 1 ? '' : 's');
        echo '  ' . $c($footer, 'bold') . "\n\n";
    }

    /**
     * Sums rowCount across all scenario buckets.
     *
     * @param array<int, array{findings:Finding[]}> $buckets
     */
    private function totalFromBuckets(array $buckets): int
    {
        $total = 0;
        foreach ($buckets as $bucket) {
            $total += $this->bucketRecordCount($bucket);
        }
        return $total;
    }

    /**
     * Reads from STDIN: scenario number drills into scenario detail,
     * 'q' / 'Q' quits. Re-prompts on invalid input.
     *
     * @param array<int, array{findings:Finding[],tables:array<string,int>}> $buckets
     * @param array<string, array{orphanFk?:?string}> $tableResults
     * @param array<string, array{pk?:?string}> $entityResults
     */
    private function interactiveLoop(array $buckets, array $tableResults, array $entityResults, bool $fixEnabled = false): bool
    {
        $c = fn(string $t, string $clr) => self::color($t, $clr);
        $showSummary = false;
        $maxScenario = count(self::SCENARIOS);
        $menuPrompt = $fixEnabled
            ? '  Enter [1-' . $maxScenario . '] for details, [f] apply fixes, [q] quit without fixing: '
            : '  Enter [1-' . $maxScenario . '] for details, [q] to quit: ';
        $invalidPrompt = $fixEnabled
            ? 'Invalid choice. Enter 1–' . $maxScenario . ', "f", or "q".'
            : 'Invalid choice. Enter 1–' . $maxScenario . ' or "q".';

        while (true) {
            if ($showSummary) {
                $this->renderSummaryTable($buckets, $this->totalFromBuckets($buckets));
            }
            $showSummary = true;

            echo $c($menuPrompt, 'bold');

            $input = strtolower(trim(fgets(STDIN)));
            echo "\n";

            if ($input === 'q') {
                echo '  ' . $c('Done — no fixes applied.', 'green') . "\n\n";
                return false;
            }

            if ($input === 'f') {
                if (!$fixEnabled) {
                    echo '  ' . $c($invalidPrompt, 'yellow') . "\n\n";
                    continue;
                }
                echo '  ' . $c('Applying fixes...', 'bold|green') . "\n\n";
                return true;
            }

            $n = (int)$input;
            if ($n < 1 || $n > $maxScenario) {
                echo '  ' . $c($invalidPrompt, 'yellow') . "\n\n";
                continue;
            }

            if (empty($buckets[$n]['findings'])) {
                echo '  ' . $c('No records in this scenario.', 'dim') . "\n\n";
                continue;
            }

            $this->renderScenarioDetail($n, $buckets[$n]['findings'], $tableResults, $entityResults);

            $detailPrompt = $fixEnabled
                ? '  [Enter] menu  |  [s] save  |  [f] apply fixes  |  [q] quit without fixing: '
                : '  [Enter] menu  |  [s] save to file  |  [q] quit: ';
            $detailInvalid = $fixEnabled
                ? 'Press Enter, "s", "f", or "q".'
                : 'Press Enter, "s", or "q".';

            while (true) {
                echo "\n" . $c($detailPrompt, 'bold');
                $input2 = strtolower(trim(fgets(STDIN)));
                if ($input2 === 'q') {
                    echo "\n  " . $c('Done — no fixes applied.', 'green') . "\n\n";
                    return false;
                }
                if ($input2 === 'f') {
                    if (!$fixEnabled) {
                        echo '  ' . $c($detailInvalid, 'yellow') . "\n";
                        continue;
                    }
                    echo "\n  " . $c('Applying fixes...', 'bold|green') . "\n\n";
                    return true;
                }
                if ($input2 === '') {
                    echo "\n";
                    break;
                }
                if ($input2 === 's') {
                    $path = $this->saveScenarioToFile($n, $buckets[$n]['findings'], $tableResults, $entityResults);
                    if ($path === null) {
                        echo '  ' . $c('Failed to write file.', 'red') . "\n";
                    } else {
                        echo '  ' . $c('Saved: ', 'green') . $path . "\n";
                    }
                    continue;
                }
                echo '  ' . $c($detailInvalid, 'yellow') . "\n";
            }
        }
    }

    /**
     * Prints every finding for a single scenario, grouped by table.
     * No row cap — user explicitly asked for full detail.
     *
     * @param int $scenario Scenario number
     * @param Finding[] $findings All findings for this scenario
     * @param array<string, array{orphanFk?:?string}> $tableResults
     * @param array<string, array{pk?:?string}> $entityResults
     */
    private function renderScenarioDetail(int $scenario, array $findings, array $tableResults, array $entityResults): void
    {
        $c   = fn(string $t, string $clr) => self::color($t, $clr);
        $sep = str_repeat('─', 66);

        $label = self::SCENARIOS[$scenario]['label'];
        $total = $this->sumRowCounts($findings);

        $byTable = $this->groupFindingsByTable($findings);
        $nTables = count($byTable);

        echo $c($sep, 'cyan') . "\n";
        echo '  ' . $c("Scenario {$scenario}: {$label}", 'bold') . "\n";
        echo '  ' . $c("{$total} record" . ($total === 1 ? '' : 's') . " across {$nTables} table" . ($nTables === 1 ? '' : 's'), 'dim') . "\n";
        echo $c($sep, 'cyan') . "\n\n";

        foreach ($byTable as $table => $rows) {
            $rowCount = $this->sumRowCounts($rows);
            $fkInfo = $this->parseFk($tableResults[$table]['orphanFk'] ?? null);

            echo '  ' . $c("▸ {$table}", 'bold|magenta') .
                 $c("  ({$rowCount} record" . ($rowCount === 1 ? ')' : 's)'), 'dim') . "\n\n";

            foreach ($rows as $f) {
                foreach ($this->findingDetailLines(
                    $f,
                    $fkInfo['column'] ?? 'entity_id',
                    $fkInfo['parentTable'] ?? null,
                    $entityResults
                ) as $line) {
                    echo $line === '' ? "\n" : $line . "\n";
                }
            }
        }
    }

    private function saveScenarioToFile(int $scenario, array $findings, array $tableResults, array $entityResults): ?string
    {
        $lines = [];
        $label = self::SCENARIOS[$scenario]['label'];
        $total = $this->sumRowCounts($findings);
        $byTable = $this->groupFindingsByTable($findings);
        $sep = str_repeat('─', 66);

        $lines[] = $sep;
        $lines[] = "Scenario {$scenario}: {$label}";
        $lines[] = "{$total} record" . ($total === 1 ? '' : 's') . ' across ' . count($byTable) . ' table' . (count($byTable) === 1 ? '' : 's');
        $lines[] = $sep;
        $lines[] = '';

        foreach ($byTable as $table => $rows) {
            $rowCount = $this->sumRowCounts($rows);
            $fkInfo = $this->parseFk($tableResults[$table]['orphanFk'] ?? null);
            $lines[] = '▸ ' . $table . '  (' . $rowCount . ' record' . ($rowCount === 1 ? ')' : 's') . ')';
            $lines[] = '';

            foreach ($rows as $f) {
                $lines = array_merge($lines, $this->findingDetailLines(
                    $f,
                    $fkInfo['column'] ?? 'entity_id',
                    $fkInfo['parentTable'] ?? null,
                    $entityResults
                ));
            }
        }

        $filename = 'settingsHealthCheck_' . $this->scenarioSlug($scenario) . '_' . date('Ymd_His') . '.txt';
        $path = getcwd() . '/' . $filename;
        return file_put_contents($path, implode("\n", $lines)) === false ? null : $path;
    }

    /**
     * Short kebab-case identifier per scenario, used in export filenames.
     */
    private function scenarioSlug(int $scenario): string
    {
        $slugs = [
            1 => 'locale',
            2 => 'orphaned',
            3 => 'required_null',
            4 => 'setting_null',
            5 => 'review_revision',
            6 => 'deleted_journal',
        ];
        return $slugs[$scenario] ?? 'unknown';
    }

    /**
     * Parses a foreign-key descriptor string (format: "user_id -> users(user_id)").
     *
     * @param string|null $fk
     * @return array{column:?string,parentTable:?string,parentColumn:?string}
     */
    private function parseFk(?string $fk): array
    {
        if ($fk === null || $fk === '') {
            return ['column' => null, 'parentTable' => null, 'parentColumn' => null];
        }
        if (preg_match('/^(\w+)\s*->\s*(\w+)\(([^)]+)\)$/', $fk, $m)) {
            return ['column' => $m[1], 'parentTable' => $m[2], 'parentColumn' => $m[3]];
        }
        return ['column' => null, 'parentTable' => null, 'parentColumn' => null];
    }

    /**
     * Human-readable explanation for a finding's reason code.
     */
    private function describeReason(Finding $f, ?string $parentTable): string
    {
        switch ($f->reason) {
            case Finding::REASON_ORPHAN_ENTITY:
                if ($f->table === 'files') {
                    return 'This blob row in the central files table is not referenced by'
                        . ' submission_files or submission_file_revisions. The database row'
                        . ' and the file on disk should be removed.';
                }
                if (Finding::isEntityOrphan($f)) {
                    $action = $f->suggestedLocale === EntityReferenceRule::ACTION_NULLIFY
                        ? 'The invalid value should be set to NULL.'
                        : 'The row(s) should be deleted.';
                    return 'Column "' . $f->settingName . '" references missing row(s) in '
                        . $f->valuePreview . '. ' . $action;
                }
                if ($f->table === 'publication_settings' && $f->settingName === 'issueId') {
                    return 'The issueId setting stores "' . $f->valuePreview
                        . '", but that issue no longer exists. The setting row should be removed.';
                }
                $where = $parentTable !== null ? ('"' . $parentTable . '"') : 'its parent table';
                return 'This row references a record in ' . $where . ' that no longer exists. The setting is dangling and should be removed.';
            case Finding::REASON_SCHEMA_MISSING_LOCALE:
                return 'A multilingual field was stored without a locale tag. PHP 8 cannot hydrate this value and will throw a TypeError.';
            case Finding::REASON_HEURISTIC_LOCALE_MISMATCH:
                return 'This setting name has both localized and non-localized rows in the same table. The empty-locale rows look out of place.';
            case Finding::REASON_REQUIRED_NULL:
                return 'A required field is empty (NULL) in the database. The schema declares it mandatory, so something wrote a broken row here.';
            case Finding::REASON_SETTING_VALUE_NULL:
                return 'The setting_value column is NULL. Settings should always have a value (even an empty string); a NULL row means the writer skipped it.';
            case Finding::REASON_REVIEW_REVISION:
                return 'This submission file has the status REVIEW_REVISION (file_stage = 15). Deleting this submission/journal in OJS CLI causes a Fatal Error due to a missing request context in updateNotification.';
            case Finding::REASON_DELETED_JOURNAL:
                $message = 'This row belongs to journal ' . (string) $f->entityId
                    . ', which no longer exists. OJS deletes only the journals and journal_settings rows,'
                    . ' so everything else it owned was left behind.';
                if ($f->rowCount > 1) {
                    $message .= ' This finding aggregates ' . number_format($f->rowCount)
                        . ' rows in ' . $f->table . ' (no row-level primary key in this schema).';
                }
                return $message;
            default:
                return 'Unrecognized issue (' . $f->reason . ').';
        }
    }

    /**
     * Formats the per-finding row label shown in scenario drill-down.
     */
    private function formatRowLabel(Finding $f): string
    {
        if ($f->table === 'files' && $f->reason === Finding::REASON_ORPHAN_ENTITY) {
            return 'Unreferenced blobs (' . number_format($f->rowCount) . ' file'
                . ($f->rowCount === 1 ? '' : 's') . ')';
        }
        if (Finding::isEntityOrphan($f)) {
            return $f->pk . ' (' . number_format($f->rowCount) . ' row'
                . ($f->rowCount === 1 ? '' : 's') . ')';
        }
        if ($f->reason === Finding::REASON_DELETED_JOURNAL && $f->rowCount > 1) {
            return 'Journal #' . $f->pk . ' (' . number_format($f->rowCount) . ' rows)';
        }
        if ($f->rowCount > 1) {
            return 'Aggregate (' . number_format($f->rowCount) . ' rows)';
        }
        if (Finding::isBulk($f)) {
            return 'Bulk fix (' . number_format($f->rowCount) . ' row'
                . ($f->rowCount === 1 ? '' : 's') . ')';
        }
        return 'Row #' . $f->pk;
    }

    /** @param Finding[] $findings @return array<string, Finding[]> */
    private function groupFindingsByTable(array $findings): array
    {
        $byTable = [];
        foreach ($findings as $f) {
            $byTable[$f->table][] = $f;
        }
        ksort($byTable);
        return $byTable;
    }

    private function resolveEntityLabel(Finding $f, string $defaultLabel, array $entityResults): string
    {
        if ($f->reason === Finding::REASON_DELETED_JOURNAL) {
            return 'journal_id';
        }
        if ($f->reason === Finding::REASON_REVIEW_REVISION) {
            return 'submission_id';
        }
        if ($f->reason === Finding::REASON_REQUIRED_NULL) {
            return $entityResults[$f->table]['pk'] ?? 'entity_id';
        }
        return $defaultLabel;
    }

    /** @return string[] */
    private function findingDetailLines(Finding $f, string $entityLabel, ?string $parentTable, array $entityResults): array
    {
        $entity = $f->entityId === null ? '(unknown)' : (string) $f->entityId;
        $label = $this->resolveEntityLabel($f, $entityLabel, $entityResults);
        $lines = [];

        if ($f->table === 'files' || Finding::isEntityOrphan($f)) {
            $lines[] = '    ' . $this->formatRowLabel($f);
            if (Finding::isEntityOrphan($f)) {
                $lines[] = '      Fix     : ' . $this->entityOrphanFixLabel($f);
            }
        } else {
            $lines[] = sprintf('    %s  (%s = %s)', $this->formatRowLabel($f), $label, $entity);
        }
        $lines[] = '      Problem : ' . $this->describeReason($f, $parentTable);

        if ($f->reason === Finding::REASON_REQUIRED_NULL) {
            $lines[] = '      Column  : ' . $f->settingName . '  (declared required, currently NULL)';
        } elseif ($f->reason === Finding::REASON_DELETED_JOURNAL) {
            $lines[] = '      Field   : ' . $f->settingName . '  (journal_id = ' . $entity . ')';
        } elseif ($f->reason === Finding::REASON_REVIEW_REVISION) {
            $lines[] = '      Field   : ' . $f->settingName . '  (submission_id = ' . $entity . ')';
        } elseif ($f->settingName !== '') {
            $localeLabel = ($f->locale === null || $f->locale === '')
                ? 'no locale tag'
                : 'locale "' . $f->locale . '"';
            $lines[] = '      Field   : ' . $f->settingName . '  (' . $localeLabel . ')';
        }

        if ($f->valuePreview !== '') {
            $valueLabel = $f->reason === Finding::REASON_DELETED_JOURNAL ? 'Via' : 'Value';
            $lines[] = '      ' . str_pad($valueLabel, 7) . ' : ' . $this->truncate($f->valuePreview, 100);
        }

        if ($f->suggestedLocale !== '' && !Finding::isEntityOrphan($f)) {
            $lines[] = '      Suggest : tag this row with locale "' . $f->suggestedLocale . '"';
        }
        $lines[] = '';
        return $lines;
    }

    private function entityOrphanFixLabel(Finding $f): string
    {
        switch ($f->suggestedLocale) {
            case EntityReferenceRule::ACTION_NULLIFY:
                return 'set invalid FK to NULL';
            case EntityReferenceRule::ACTION_DELETE_OPTIONAL:
                return 'delete rows with invalid optional FK';
            default:
                return 'delete rows with invalid required FK';
        }
    }

    /**
     * Truncates a string to $max characters, appending "..." when trimmed.
     */
    private function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max - 3) . '...';
    }
}
