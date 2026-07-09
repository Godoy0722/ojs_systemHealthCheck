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
 * @brief Computes finding statistics and renders the stdout summary.
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

    /**
     * Wrap $text in ANSI color codes. Multiple colors can be combined by
     * separating them with a pipe, e.g. "bold|red". Pass an empty string as
     * $color to skip wrapping (returns $text unchanged).
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
     * Returns the total number of findings. Formerly also computed per-table
     * breakdowns; now a thin count wrapper kept as its own method so the
     * call site doesn't couple to count() semantics.
     *
     * @param Finding[] $findings
     * @return int
     */
    public function computeStats(array $findings): int
    {
        return count($findings);
    }

    private const RULE_SINGLE = '────────────────────────────────────────────────────────────────────────────────';

    /**
     * Renders the full stdout report: a detailed-findings section grouped by
     * table, or a short "No findings." notice when the scan turned up nothing.
     *
     * @param array{detailCap?:int,findings?:Finding[],tableResults?:array<string,array{orphanFk?:?string}>} $context
     * @return string
     */
    public function renderSummary(array $context): string
    {
        $findings = $context['findings'] ?? [];
        if (empty($findings)) {
            return "\n  " . self::color('No findings.', 'green') . "\n";
        }

        $cap = (int) ($context['detailCap'] ?? 50);
        $tableResults = $context['tableResults'] ?? [];
        $lines = $this->renderFindingsDetail($findings, $cap, $tableResults);

        return implode("\n", $lines) . "\n";
    }

    /**
     * Builds the detailed-findings block: one sub-section per table, each
     * row annotated with reason, value preview, and suggested fix. Caps
     * output at $cap rows to avoid flooding the terminal.
     *
     * @param Finding[] $findings
     * @param int $cap Maximum rows to render before truncating
     * @param array<string, array{kind:string,settingsChecked:string[],findingsCount:int,status:string,note:string,orphanFk?:?string}> $tableResults
     * @return string[]
     */
    private function renderFindingsDetail(array $findings, int $cap, array $tableResults = []): array
    {
        $c = fn(string $t, string $clr) => self::color($t, $clr);

        $lines = [];
        $lines[] = '';
        $lines[] = $c(self::RULE_SINGLE, 'bold|cyan');
        $lines[] = '  ' . $c('Detailed findings (' . count($findings) . ')', 'bold');
        $lines[] = $c(self::RULE_SINGLE, 'bold|cyan');

        $byTable = [];
        foreach ($findings as $f) {
            $byTable[$f->table][] = $f;
        }
        ksort($byTable);

        $shown = 0;
        foreach ($byTable as $table => $rows) {
            $lines[] = '';
            $lines[] = '  ' . $c('Table: ' . $table, 'bold') . '   (' . count($rows) . ' issue' . (count($rows) === 1 ? '' : 's') . ')';
            $fkInfo = $this->parseFk($tableResults[$table]['orphanFk'] ?? null);
            $entityLabel = $fkInfo['column'] ?? 'entity_id';
            $parentTable = $fkInfo['parentTable'] ?? null;

            foreach ($rows as $f) {
                if ($shown >= $cap) {
                    break 2;
                }
                $entity = $f->entityId === null ? '(unknown)' : (string) $f->entityId;
                $lines[] = sprintf('    Row #%s  (%s = %s)', (string) $f->pk, $entityLabel, $entity);
                $lines[] = '      ' . $c('Problem', 'red') . ' : ' . $this->describeReason($f, $parentTable);
                if ($f->reason === Finding::REASON_REQUIRED_NULL) {
                    $lines[] = '      ' . $c('Column', 'cyan') . '  : ' . $f->settingName . '  (declared required, currently NULL)';
                } elseif ($f->settingName !== '') {
                    $localeLabel = ($f->locale === null || $f->locale === '') ? 'no locale tag' : 'locale "' . $f->locale . '"';
                    $lines[] = '      ' . $c('Field', 'cyan') . '   : ' . $f->settingName . '  (' . $localeLabel . ')';
                }
                if ($f->valuePreview !== '') {
                    $lines[] = '      ' . $c('Value', 'dim') . '   : ' . $this->truncate($f->valuePreview, 100);
                }
                if ($f->suggestedLocale !== '') {
                    $lines[] = '      ' . $c('Suggest', 'green') . ' : tag this row with locale "' . $f->suggestedLocale . '"';
                }
                $shown++;
            }
        }

        $remaining = count($findings) - $shown;
        if ($remaining > 0) {
            $lines[] = '';
            $lines[] = '  ... and ' . $remaining . ' more issue' . ($remaining === 1 ? '' : 's');
        }

        return $lines;
    }

    /**
     * Parses a foreign-key descriptor string produced by the orphan pass
     * (format: "user_id -> users(user_id)") into its three parts.
     *
     * @param string|null $fk FK descriptor or null when no FK was resolved
     * @return array{column:?string,parentTable:?string,parentColumn:?string}
     */
    private function parseFk(?string $fk): array
    {
        if ($fk === null || $fk === '') {
            return ['column' => null, 'parentTable' => null, 'parentColumn' => null];
        }
        // Format: "user_id -> users(user_id)"
        if (preg_match('/^(\w+)\s*->\s*(\w+)\(([^)]+)\)$/', $fk, $m)) {
            return ['column' => $m[1], 'parentTable' => $m[2], 'parentColumn' => $m[3]];
        }
        return ['column' => null, 'parentTable' => null, 'parentColumn' => null];
    }

    /**
     * Returns a human-readable explanation for a finding's reason code.
     *
     * @param Finding $f The finding to describe
     * @param string|null $parentTable Parent table name (for orphan context)
     * @return string
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
            default:
                return 'Unrecognized issue (' . $f->reason . ').';
        }
    }

    /**
     * Truncates a string to $max characters, appending "..." when trimmed.
     * Uses mb_* functions for safe multi-byte handling.
     *
     * @param string $s
     * @param int $max
     * @return string
     */
    private function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max - 3) . '...';
    }
}
