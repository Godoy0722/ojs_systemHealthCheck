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
    // ── ANSI terminal colors ──────────────────────────────────────────────

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
     * Returns the total number of findings.
     *
     * @param Finding[] $findings
     * @return int
     */
    public function computeStats(array $findings): int
    {
        return count($findings);
    }

    // ── Scenario definitions ──────────────────────────────────────────────

    /**
     * Each scenario maps a menu number to one or more Finding reason codes.
     * Order here determines the numbered menu order.
     */
    private const SCENARIOS = [
        1 => [
            'label'   => 'Missing locale (schema-driven)',
            'reasons' => [Finding::REASON_SCHEMA_MISSING_LOCALE],
        ],
        2 => [
            'label'   => 'Missing locale (heuristic)',
            'reasons' => [Finding::REASON_HEURISTIC_LOCALE_MISMATCH],
        ],
        3 => [
            'label'   => 'Orphaned settings',
            'reasons' => [Finding::REASON_ORPHAN_ENTITY],
        ],
        4 => [
            'label'   => 'Required fields NULL',
            'reasons' => [Finding::REASON_REQUIRED_NULL],
        ],
        5 => [
            'label'   => 'NULL setting_value',
            'reasons' => [Finding::REASON_SETTING_VALUE_NULL],
        ],
        6 => [
            'label'   => 'REVIEW_REVISION files',
            'reasons' => [Finding::REASON_REVIEW_REVISION],
        ],
        7 => [
            'label'   => 'Deleted journal leftovers',
            'reasons' => [Finding::REASON_DELETED_JOURNAL],
        ],
    ];

    // ── Public entry point ────────────────────────────────────────────────

    /**
     * Prints the interactive report: summary table then a drill-down loop.
     * When STDIN is not a TTY (piped input), prints the summary table only
     * and exits without entering the interactive loop.
     *
     * @param array{findings?:Finding[],tableResults?:array<string,array{orphanFk?:?string}>,entityResults?:array<string,array{pk?:?string}>} $context
     */
    public function renderInteractive(array $context): void
    {
        $findings = $context['findings'] ?? [];

        if (empty($findings)) {
            echo "\n  " . self::color('No findings — database looks clean.', 'green') . "\n\n";
            return;
        }

        $tableResults = $context['tableResults'] ?? [];
        $entityResults = $context['entityResults'] ?? [];

        // Group findings into scenario buckets.
        $buckets = $this->buildBuckets($findings);

        // Always print the summary table.
        $this->renderSummaryTable($buckets, count($findings));

        // Only enter interactive loop when STDIN is a real terminal.
        if (!(function_exists('stream_isatty') && stream_isatty(STDIN))) {
            echo "\n";
            return;
        }

        $this->interactiveLoop($buckets, $tableResults, $entityResults);
    }

    // ── Bucket helpers ────────────────────────────────────────────────────

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
            $buckets[$scenario]['tables'][$table] = ($buckets[$scenario]['tables'][$table] ?? 0) + 1;
        }
        return $buckets;
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

    // ── Summary table ─────────────────────────────────────────────────────

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

        // Table layout: 4 cols — #, Scenario, Tables, Records
        // Widths: 3 + 36 + 9 + 10 = 58 → use 60 for good measure
        $wLabel  = 38;  // scenario label
        $wTables = 8;   // table count
        $wRecs   = 8;   // record count

        $sepTop    = '┌' . str_repeat('─', $wLabel + $wTables + $wRecs + 10) . '┐';
        $sepHead   = '├' . str_repeat('─', $wLabel + $wTables + $wRecs + 10) . '┤';
        $sepBottom = '└' . str_repeat('─', $wLabel + $wTables + $wRecs + 10) . '┘';

        echo "\n";
        echo $c($sepTop, 'cyan') . "\n";
        $title = 'Settings Health Check — Scan Results';
        $pad = (int)((mb_strlen($sepTop) - 2 - mb_strlen($title)) / 2);
        echo $c('│' . str_repeat(' ', $pad) . $title . str_repeat(' ', mb_strlen($sepTop) - 2 - $pad - mb_strlen($title)) . '│', 'bold|cyan') . "\n";
        echo $c($sepHead, 'cyan') . "\n";

        // Header row
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

        // Data rows
        foreach ($buckets as $n => $bucket) {
            $count  = count($bucket['findings']);
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

        // Footer
        echo $c($sepBottom, 'cyan') . "\n";
        $footer = "Total: {$total} finding" . ($total === 1 ? '' : 's') .
                   " across {$scenariosWithFindings} scenario" . ($scenariosWithFindings === 1 ? '' : 's');
        echo '  ' . $c($footer, 'bold') . "\n\n";
    }

    // ── Interactive loop ──────────────────────────────────────────────────

    /**
     * Reads from STDIN: number (1-7) drills into scenario detail,
     * 'q' / 'Q' quits. Re-prompts on invalid input.
     *
     * @param array<int, array{findings:Finding[],tables:array<string,int>}> $buckets
     * @param array<string, array{orphanFk?:?string}> $tableResults
     * @param array<string, array{pk?:?string}> $entityResults
     */
    private function interactiveLoop(array $buckets, array $tableResults, array $entityResults): void
    {
        $c = fn(string $t, string $clr) => self::color($t, $clr);

        while (true) {
            echo $c('  Enter [1-7] to see details, [q] to quit: ', 'bold');

            $input = strtolower(trim(fgets(STDIN)));
            echo "\n";

            if ($input === 'q') {
                echo '  ' . $c('Done.', 'green') . "\n\n";
                break;
            }

            $n = (int)$input;
            if ($n < 1 || $n > 7) {
                echo '  ' . $c('Invalid choice. Enter a number 1–7 or "q".', 'yellow') . "\n\n";
                continue;
            }

            if (empty($buckets[$n]['findings'])) {
                echo '  ' . $c('No records in this scenario.', 'dim') . "\n\n";
                continue;
            }

            $this->renderScenarioDetail($n, $buckets[$n]['findings'], $tableResults, $entityResults);

            // Post-detail prompt
            while (true) {
                echo "\n" . $c('  [Enter] menu  |  [s] save to file  |  [q] quit: ', 'bold');
                $input2 = strtolower(trim(fgets(STDIN)));
                if ($input2 === 'q') {
                    echo "\n  " . $c('Done.', 'green') . "\n\n";
                    return;
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
                echo '  ' . $c('Press Enter, "s", or "q".', 'yellow') . "\n";
            }
        }
    }

    // ── Scenario detail ───────────────────────────────────────────────────

    /**
     * Prints every finding for a single scenario, grouped by table.
     * No row cap — user explicitly asked for full detail.
     *
     * @param int $scenario Scenario number (1-7)
     * @param Finding[] $findings All findings for this scenario
     * @param array<string, array{orphanFk?:?string}> $tableResults
     * @param array<string, array{pk?:?string}> $entityResults
     */
    private function renderScenarioDetail(int $scenario, array $findings, array $tableResults, array $entityResults): void
    {
        $c   = fn(string $t, string $clr) => self::color($t, $clr);
        $sep = str_repeat('─', 66);

        $label = self::SCENARIOS[$scenario]['label'];
        $total = count($findings);

        // Group by table
        $byTable = [];
        foreach ($findings as $f) {
            $byTable[$f->table][] = $f;
        }
        ksort($byTable);

        $nTables = count($byTable);

        echo $c($sep, 'cyan') . "\n";
        echo '  ' . $c("Scenario {$scenario}: {$label}", 'bold') . "\n";
        echo '  ' . $c("{$total} record" . ($total === 1 ? '' : 's') . " across {$nTables} table" . ($nTables === 1 ? '' : 's'), 'dim') . "\n";
        echo $c($sep, 'cyan') . "\n\n";

        foreach ($byTable as $table => $rows) {
            $rowCount = count($rows);
            $fkInfo = $this->parseFk($tableResults[$table]['orphanFk'] ?? null);
            $entityLabel = $fkInfo['column'] ?? 'entity_id';
            $parentTable = $fkInfo['parentTable'] ?? null;

            echo '  ' . $c("▸ {$table}", 'bold|magenta') .
                 $c("  ({$rowCount} issue" . ($rowCount === 1 ? ')' : 's)'), 'dim') . "\n\n";

            foreach ($rows as $f) {
                $entity = $f->entityId === null ? '(unknown)' : (string)$f->entityId;
                $label = $entityLabel;
                if ($f->reason === Finding::REASON_DELETED_JOURNAL) {
                    // entityId carries the dead journal id, not this table's FK value
                    $label = 'journal_id';
                } elseif ($f->reason === Finding::REASON_REVIEW_REVISION) {
                    // entityId carries the submission id, not a settings FK
                    $label = 'submission_id';
                } elseif ($f->reason === Finding::REASON_REQUIRED_NULL) {
                    // entityId is the row's own pk — name it after the table's pk column
                    $label = $entityResults[$f->table]['pk'] ?? 'entity_id';
                }
                echo sprintf('    %-12s %s', $c('Row #' . $f->pk, 'bold'), $c("({$label} = {$entity})", 'dim')) . "\n";
                echo '      ' . $c('Problem', 'red') . ' : ' . $this->describeReason($f, $parentTable) . "\n";

                if ($f->reason === Finding::REASON_REQUIRED_NULL) {
                    echo '      ' . $c('Column', 'cyan') . '  : ' . $f->settingName .
                         $c('  (declared required, currently NULL)', 'dim') . "\n";
                } elseif ($f->reason === Finding::REASON_DELETED_JOURNAL) {
                    echo '      ' . $c('Field', 'cyan') . '   : ' . $f->settingName .
                         $c('  (journal_id = ' . $entity . ')', 'dim') . "\n";
                } elseif ($f->reason === Finding::REASON_REVIEW_REVISION) {
                    echo '      ' . $c('Field', 'cyan') . '   : ' . $f->settingName .
                         $c('  (submission_id = ' . $entity . ')', 'dim') . "\n";
                } elseif ($f->settingName !== '') {
                    $localeLabel = ($f->locale === null || $f->locale === '')
                        ? $c('no locale tag', 'red')
                        : 'locale "' . $f->locale . '"';
                    echo '      ' . $c('Field', 'cyan') . '   : ' . $f->settingName . '  (' . $localeLabel . ')' . "\n";
                }

                if ($f->valuePreview !== '') {
                    $valueLabel = $f->reason === Finding::REASON_DELETED_JOURNAL ? 'Via' : 'Value';
                    echo '      ' . $c($valueLabel, 'dim') . str_repeat(' ', 7 - mb_strlen($valueLabel)) . ': ' . $this->truncate($f->valuePreview, 100) . "\n";
                }

                if ($f->suggestedLocale !== '') {
                    echo '      ' . $c('Suggest', 'green') . ' : tag this row with locale "' . $f->suggestedLocale . '"' . "\n";
                }
                echo "\n";
            }
        }
    }

    // ── File export ──────────────────────────────────────────────────────

    /**
     * Writes the scenario detail to a plain-text file (no ANSI codes).
     * Returns the absolute path to the saved file.
     *
     * @param int $scenario Scenario number (1-7)
     * @param Finding[] $findings All findings for this scenario
     * @param array<string, array{orphanFk?:?string}> $tableResults
     * @param array<string, array{pk?:?string}> $entityResults
     * @return string|null Absolute path of the saved file, null on failure
     */
    private function saveScenarioToFile(int $scenario, array $findings, array $tableResults, array $entityResults): string
    {
        $lines = [];

        $label = self::SCENARIOS[$scenario]['label'];
        $total = count($findings);

        $byTable = [];
        foreach ($findings as $f) {
            $byTable[$f->table][] = $f;
        }
        ksort($byTable);

        $nTables = count($byTable);

        $sep = str_repeat('─', 66);

        $lines[] = $sep;
        $lines[] = "Scenario {$scenario}: {$label}";
        $lines[] = "{$total} record" . ($total === 1 ? '' : 's') . " across {$nTables} table" . ($nTables === 1 ? '' : 's');
        $lines[] = $sep;
        $lines[] = '';

        foreach ($byTable as $table => $rows) {
            $rowCount = count($rows);
            $fkInfo = $this->parseFk($tableResults[$table]['orphanFk'] ?? null);
            $entityLabel = $fkInfo['column'] ?? 'entity_id';
            $parentTable = $fkInfo['parentTable'] ?? null;

            $lines[] = "▸ {$table}  ({$rowCount} issue" . ($rowCount === 1 ? ')' : 's)');
            $lines[] = '';

            foreach ($rows as $f) {
                $entity = $f->entityId === null ? '(unknown)' : (string)$f->entityId;
                $label = $entityLabel;
                if ($f->reason === Finding::REASON_DELETED_JOURNAL) {
                    // entityId carries the dead journal id, not this table's FK value
                    $label = 'journal_id';
                } elseif ($f->reason === Finding::REASON_REVIEW_REVISION) {
                    // entityId carries the submission id, not a settings FK
                    $label = 'submission_id';
                } elseif ($f->reason === Finding::REASON_REQUIRED_NULL) {
                    // entityId is the row's own pk — name it after the table's pk column
                    $label = $entityResults[$f->table]['pk'] ?? 'entity_id';
                }
                $lines[] = sprintf('    Row #%s  (%s = %s)', $f->pk, $label, $entity);
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

                if ($f->suggestedLocale !== '') {
                    $lines[] = '      Suggest : tag this row with locale "' . $f->suggestedLocale . '"';
                }
                $lines[] = '';
            }
        }

        $slug = $this->scenarioSlug($scenario);
        $timestamp = date('Ymd_His');
        $filename = "settingsHealthCheck_{$slug}_{$timestamp}.txt";
        $path = getcwd() . '/' . $filename;

        $bytes = file_put_contents($path, implode("\n", $lines));
        if ($bytes === false) {
            return null;
        }

        return $path;
    }

    /**
     * Short kebab-case identifier per scenario, used in export filenames.
     */
    private function scenarioSlug(int $scenario): string
    {
        $slugs = [
            1 => 'locale_schema',
            2 => 'locale_heuristic',
            3 => 'orphaned',
            4 => 'required_null',
            5 => 'setting_null',
            6 => 'review_revision',
            7 => 'deleted_journal',
        ];
        return $slugs[$scenario] ?? 'unknown';
    }

    // ── Shared utilities ──────────────────────────────────────────────────

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
                return 'This row belongs to journal ' . (string) $f->entityId . ', which no longer exists. OJS deletes only the journals and journal_settings rows, so everything else it owned was left behind.';
            default:
                return 'Unrecognized issue (' . $f->reason . ').';
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
