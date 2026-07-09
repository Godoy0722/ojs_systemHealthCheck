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
    /**
     * @param iterable<Finding> $findings
     * @return array{rows:int,byTable:array<string,int>,settingsByTable:array<string,array<string,true>>,reasonsByTable:array<string,array<string,int>>}
     */
    public function computeStats(iterable $findings): array
    {
        $stats = [
            'rows' => 0,
            'byTable' => [],
            'settingsByTable' => [],
            'reasonsByTable' => [],
        ];

        foreach ($findings as $f) {
            $stats['rows']++;
            $stats['byTable'][$f->table] = ($stats['byTable'][$f->table] ?? 0) + 1;
            $stats['settingsByTable'][$f->table][$f->settingName] = true;
            $stats['reasonsByTable'][$f->table][$f->reason]
                = ($stats['reasonsByTable'][$f->table][$f->reason] ?? 0) + 1;
        }

        return $stats;
    }

    private const RULE_SINGLE = '────────────────────────────────────────────────────────────────────────────────';

    /**
     * Renders only the detailed-findings section. Returns a short notice when
     * the scan turned up nothing.
     *
     * @param array{detailCap?:int,findings?:Finding[],tableResults?:array<string,array{orphanFk?:?string}>} $context
     */
    public function renderSummary(array $context): string
    {
        $findings = $context['findings'] ?? [];
        if (empty($findings)) {
            return "\n  No findings.\n";
        }

        $cap = (int) ($context['detailCap'] ?? 50);
        $tableResults = $context['tableResults'] ?? [];
        $lines = $this->renderFindingsDetail($findings, $cap, $tableResults);

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param Finding[] $findings
     * @param array<string, array{kind:string,settingsChecked:string[],findingsCount:int,status:string,note:string,orphanFk?:?string}> $tableResults
     * @return string[]
     */
    private function renderFindingsDetail(array $findings, int $cap, array $tableResults = []): array
    {
        $lines = [];
        $lines[] = '';
        $lines[] = self::RULE_SINGLE;
        $lines[] = '  Detailed findings (' . count($findings) . ')';
        $lines[] = self::RULE_SINGLE;

        $byTable = [];
        foreach ($findings as $f) {
            $byTable[$f->table][] = $f;
        }
        ksort($byTable);

        $shown = 0;
        foreach ($byTable as $table => $rows) {
            $lines[] = '';
            $lines[] = '  Table: ' . $table . '   (' . count($rows) . ' issue' . (count($rows) === 1 ? '' : 's') . ')';
            $fkInfo = $this->parseFk($tableResults[$table]['orphanFk'] ?? null);
            $entityLabel = $fkInfo['column'] ?? 'entity_id';
            $parentTable = $fkInfo['parentTable'] ?? null;

            foreach ($rows as $f) {
                if ($shown >= $cap) {
                    break 2;
                }
                $entity = $f->entityId === null ? '(unknown)' : (string) $f->entityId;
                $lines[] = sprintf('    Row #%s  (%s = %s)', (string) $f->pk, $entityLabel, $entity);
                $lines[] = '      Problem : ' . $this->describeReason($f, $parentTable);
                if ($f->reason === Finding::REASON_REQUIRED_NULL) {
                    $lines[] = '      Column  : ' . $f->settingName . '  (declared required, currently NULL)';
                } elseif ($f->settingName !== '') {
                    $localeLabel = ($f->locale === null || $f->locale === '') ? 'no locale tag' : 'locale "' . $f->locale . '"';
                    $lines[] = '      Field   : ' . $f->settingName . '  (' . $localeLabel . ')';
                }
                if ($f->valuePreview !== '') {
                    $lines[] = '      Value   : ' . $this->truncate($f->valuePreview, 100);
                }
                if ($f->suggestedLocale !== '') {
                    $lines[] = '      Suggest : tag this row with locale "' . $f->suggestedLocale . '"';
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

    private function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max - 3) . '...';
    }
}
