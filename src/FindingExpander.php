<?php

/**
 * @file tools/SettingsHealthCheck/FindingExpander.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FindingExpander
 *
 * @brief Expands aggregate scan findings into row-level findings for reports.
 *        Scan/fix stay bulk; detail views query offending rows on demand.
 */

namespace APP\tools\settingsHealthCheck\src;

final class FindingExpander
{
    /** @var IlluminateDatabaseGateway */
    private $gateway;

    /** @var JournalCascadeRegistry|null */
    private $cascadeRegistry;

    /** @var OrphanReferenceCleaner */
    private $entityCleaner;

    public function __construct(IlluminateDatabaseGateway $gateway, ?JournalCascadeRegistry $cascadeRegistry = null)
    {
        $this->gateway = $gateway;
        $this->cascadeRegistry = $cascadeRegistry;
        $this->entityCleaner = new OrphanReferenceCleaner($gateway);
    }

    /**
     * @param Finding[] $findings
     * @return Finding[]
     */
    public function expand(array $findings): array
    {
        $expanded = [];
        foreach ($findings as $finding) {
            foreach ($this->expandOne($finding) as $rowFinding) {
                $expanded[] = $rowFinding;
            }
        }
        return $expanded;
    }

    /** @return Finding[] */
    private function expandOne(Finding $finding): array
    {
        if (Finding::isEntityOrphan($finding)) {
            return $this->entityCleaner->expandEntityOrphanFinding($finding);
        }

        if ($finding->table === 'files' && $finding->pk === 'unreferenced' && $finding->rowCount > 1) {
            return $this->expandUnreferencedFiles($finding);
        }

        if (!Finding::isBulk($finding)) {
            return [$finding];
        }

        $pk = (string) $finding->pk;
        $detail = '';
        if (strpos($pk, Finding::BULK_PREFIX) === 0) {
            $rest = substr($pk, strlen(Finding::BULK_PREFIX));
            $colon = strpos($rest, ':');
            $kind = $colon === false ? $rest : substr($rest, 0, $colon);
            $detail = $colon === false ? '' : substr($rest, $colon + 1);
        } else {
            return [$finding];
        }

        switch ($kind) {
            case 'locale-schema':
                return $this->expandLocaleRows(
                    $finding,
                    $finding->entityId !== null ? explode('|', (string) $finding->entityId) : [],
                    false
                );
            case 'locale-heuristic':
                return $this->expandLocaleRows(
                    $finding,
                    $finding->entityId !== null ? explode('|', (string) $finding->entityId) : [],
                    true
                );
            case 'orphan':
                return $this->expandOrphanSettings($finding, $detail);
            case 'issueId':
                return $this->expandIssueIdSettings($finding);
            case 'required-null':
                return $this->expandRequiredNull($finding, $detail);
            case 'setting-null':
                return $this->expandSettingNull($finding);
            case 'review':
                return $this->expandReviewFiles($finding);
            case 'deleted-journal-table':
                return $this->expandDeletedJournalTable($finding);
            default:
                return [$finding];
        }
    }

    /** @param string[] $settingNames @return Finding[] */
    private function expandLocaleRows(Finding $finding, array $settingNames, bool $heuristic): array
    {
        if (empty($settingNames)) {
            return [$finding];
        }

        $rows = $heuristic
            ? $this->gateway->getEmptyLocaleRowsForSettings($finding->table, $settingNames)
            : $this->gateway->getMultilingualOffenders($finding->table, $settingNames);

        $reason = $heuristic
            ? Finding::REASON_HEURISTIC_LOCALE_MISMATCH
            : Finding::REASON_SCHEMA_MISSING_LOCALE;

        $expanded = [];
        foreach ($rows as $row) {
            $expanded[] = new Finding(
                $finding->table,
                $row['pk'],
                $row['fk'],
                $row['setting_name'],
                $row['locale'],
                $row['setting_value'],
                $reason,
                $finding->suggestedLocale !== '' ? $finding->suggestedLocale : 'en'
            );
        }
        return $expanded !== [] ? $expanded : [$finding];
    }

    /** @return Finding[] */
    private function expandOrphanSettings(Finding $finding, string $detail): array
    {
        $parts = explode(':', $detail);
        if (count($parts) !== 4) {
            return [$finding];
        }
        [$fkCol, $parentTable, $parentCol, $ignoreZeroFlag] = $parts;

        $expanded = [];
        foreach ($this->gateway->findOrphans(
            $finding->table,
            $fkCol,
            $parentTable,
            $parentCol,
            $ignoreZeroFlag === '1'
        ) as $row) {
            $expanded[] = new Finding(
                $finding->table,
                $row['pk'],
                $row['fk'],
                $row['setting_name'] !== '' ? $row['setting_name'] : $fkCol,
                $row['locale'],
                $row['setting_value'],
                Finding::REASON_ORPHAN_ENTITY,
                ''
            );
        }
        return $expanded !== [] ? $expanded : [$finding];
    }

    /** @return Finding[] */
    private function expandIssueIdSettings(Finding $finding): array
    {
        $expanded = [];
        foreach ($this->gateway->findInvalidPublicationIssueIdSettings() as $row) {
            $expanded[] = new Finding(
                'publication_settings',
                $row['publication_id'],
                $row['submission_id'],
                'issueId',
                $row['locale'],
                $row['setting_value'],
                Finding::REASON_ORPHAN_ENTITY,
                ''
            );
        }
        return $expanded !== [] ? $expanded : [$finding];
    }

    /** @return Finding[] */
    private function expandRequiredNull(Finding $finding, string $column): array
    {
        $pk = $this->gateway->getTablePrimaryKey($finding->table);
        if ($pk === null) {
            return [$finding];
        }

        $expanded = [];
        foreach ($this->gateway->findRowsWithNullColumn($finding->table, $pk, $column) as $row) {
            $expanded[] = new Finding(
                $finding->table,
                $row['pk'],
                null,
                $column,
                null,
                null,
                Finding::REASON_REQUIRED_NULL,
                ''
            );
        }
        return $expanded !== [] ? $expanded : [$finding];
    }

    /** @return Finding[] */
    private function expandSettingNull(Finding $finding): array
    {
        $expanded = [];
        foreach ($this->gateway->findRowsWithNullSettingValue($finding->table) as $row) {
            $expanded[] = new Finding(
                $finding->table,
                $row['pk'],
                $row['fk'],
                $row['setting_name'],
                $row['locale'],
                $row['setting_value'],
                Finding::REASON_SETTING_VALUE_NULL,
                ''
            );
        }
        return $expanded !== [] ? $expanded : [$finding];
    }

    /** @return Finding[] */
    private function expandReviewFiles(Finding $finding): array
    {
        $expanded = [];
        foreach ($this->gateway->findReviewRevisionFiles() as $row) {
            $expanded[] = new Finding(
                'submission_files',
                $row['pk'],
                $row['fk'],
                $row['setting_name'],
                $row['locale'],
                $row['setting_value'],
                Finding::REASON_REVIEW_REVISION,
                ''
            );
        }
        return $expanded !== [] ? $expanded : [$finding];
    }

    /** @return Finding[] */
    private function expandUnreferencedFiles(Finding $finding): array
    {
        $expanded = [];
        foreach ($this->gateway->findUnreferencedFileIds() as $fileId) {
            $expanded[] = new Finding(
                'files',
                $fileId,
                null,
                'blob',
                null,
                null,
                Finding::REASON_ORPHAN_ENTITY,
                ''
            );
        }
        return $expanded !== [] ? $expanded : [$finding];
    }

    /** @return Finding[] */
    private function expandDeletedJournalTable(Finding $finding): array
    {
        if ($this->cascadeRegistry === null) {
            return [$finding];
        }

        try {
            $plan = $this->cascadeRegistry->build();
            $deadIds = $this->gateway->findDeadJournalIds($this->cascadeRegistry->getDirectRootColumns());
        } catch (\Throwable $e) {
            return [$finding];
        }

        if (empty($deadIds)) {
            return [$finding];
        }

        $planByTable = [];
        foreach ($plan as $planStep) {
            $planByTable[$planStep['table']] = $planStep;
        }
        $step = $planByTable[$finding->table] ?? null;
        if ($step === null) {
            return [$finding];
        }

        if (!empty($step['aggregate'])) {
            return [$finding];
        }

        $expanded = [];
        if ($step['source'] === 'journal') {
            foreach ($deadIds as $journalId) {
                foreach ($this->gateway->findRowIdsByColumn(
                    $finding->table,
                    $step['identity'],
                    $step['column'],
                    [$journalId],
                    $step['assocType']
                ) as $rowId) {
                    $expanded[] = new Finding(
                        $finding->table,
                        $rowId,
                        $journalId,
                        $step['column'],
                        null,
                        $step['via'],
                        Finding::REASON_DELETED_JOURNAL,
                        ''
                    );
                }
            }
        } else {
            foreach ($this->gateway->findRowIdsByDeadJournalPath($step, $planByTable, $deadIds) as $rowId) {
                $expanded[] = new Finding(
                    $finding->table,
                    $rowId,
                    null,
                    $step['column'],
                    null,
                    $step['via'],
                    Finding::REASON_DELETED_JOURNAL,
                    ''
                );
            }
        }

        return $expanded !== [] ? $expanded : [$finding];
    }
}
