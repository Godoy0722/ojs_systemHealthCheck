<?php

/**
 * @file tools/SettingsHealthCheck/Fixer.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Fixer
 *
 * @brief Applies remediations for Scanner findings. Only invoked with --fix.
 */

namespace APP\tools\settingsHealthCheck\src;

final class Fixer
{
    /** @var IlluminateDatabaseGateway */
    private $gateway;

    /** @var JournalCascadeRegistry|null */
    private $cascadeRegistry;

    private string $defaultLocale;

    /** @var string[] */
    private array $warnings = [];

    public function __construct(IlluminateDatabaseGateway $gateway, ?JournalCascadeRegistry $cascadeRegistry = null)
    {
        $this->gateway = $gateway;
        $this->cascadeRegistry = $cascadeRegistry;
        $locale = $gateway->getSitePrimaryLocale();
        $this->defaultLocale = $locale !== '' ? $locale : 'en';
    }

    /**
     * @param Finding[] $findings
     * @return array{orphansDeleted:int, orphanFilesDeleted:int, entityReferencesRecovered:int, entityOrphansFixed:int, localesFixed:int, reviewFilesDeleted:int, journalRecordsDeleted:int, alreadyRemoved:int, skipped:int, failed:int}
     */
    public function fix(array $findings): array
    {
        $result = [
            'orphansDeleted' => 0,
            'orphanFilesDeleted' => 0,
            'entityReferencesRecovered' => 0,
            'entityOrphansFixed' => 0,
            'localesFixed' => 0,
            'reviewFilesDeleted' => 0,
            'journalRecordsDeleted' => 0,
            'alreadyRemoved' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $entityCleaner = new OrphanReferenceCleaner($this->gateway);
        $result['entityReferencesRecovered'] = $entityCleaner->recoverReferences();

        $journalFindings = [];
        $rest = [];
        foreach ($findings as $finding) {
            if ($finding->reason === Finding::REASON_DELETED_JOURNAL) {
                $journalFindings[] = $finding;
            } else {
                $rest[] = $finding;
            }
        }

        if (!empty($journalFindings)) {
            $result['journalRecordsDeleted'] = $this->deleteDeadJournals($journalFindings, $result);
        }

        $fixedEntityRules = [];
        $fixedBulk = [];
        foreach ($rest as $finding) {
            try {
                if (Finding::isBulk($finding)) {
                    $bulkKey = $finding->table . ':' . $finding->pk;
                    if (isset($fixedBulk[$bulkKey])) {
                        continue;
                    }
                    $fixedBulk[$bulkKey] = true;
                    if ($this->fixBulkFinding($finding, $entityCleaner, $result)) {
                        continue;
                    }
                }

                switch ($finding->reason) {
                    case Finding::REASON_ORPHAN_ENTITY:
                        if ($finding->table === 'files') {
                            if ($finding->pk !== 'unreferenced') {
                                break;
                            }
                            $deleted = $this->gateway->deleteUnreferencedFiles();
                            if ($deleted > 0) {
                                $result['orphanFilesDeleted'] += $deleted;
                            } elseif ($finding->rowCount > 0) {
                                $result['failed']++;
                            }
                            break;
                        }
                        if (Finding::isEntityOrphan($finding)) {
                            $ruleKey = (string) $finding->pk;
                            if (isset($fixedEntityRules[$ruleKey])) {
                                break;
                            }
                            $fixedEntityRules[$ruleKey] = true;
                            $fixed = $entityCleaner->fixFinding($finding);
                            if ($fixed > 0) {
                                $result['entityOrphansFixed'] += $fixed;
                            } elseif ($finding->rowCount > 0) {
                                $result['failed']++;
                            }
                            break;
                        }
                        $deleted = $this->gateway->deleteSettingRow(
                            $finding->table,
                            $finding->pk,
                            $finding->settingName,
                            $finding->locale
                        );
                        if ($deleted > 0) {
                            $result['orphansDeleted'] += $deleted;
                        } elseif ($this->settingRowAlreadyGone($finding)) {
                            $result['alreadyRemoved']++;
                        } else {
                            $result['failed']++;
                        }
                        break;

                    case Finding::REASON_SCHEMA_MISSING_LOCALE:
                    case Finding::REASON_HEURISTIC_LOCALE_MISMATCH:
                        $locale = $finding->suggestedLocale !== '' ? $finding->suggestedLocale : $this->defaultLocale;
                        $updated = $this->gateway->setSettingRowLocale(
                            $finding->table,
                            $finding->pk,
                            $finding->settingName,
                            $finding->locale,
                            $locale
                        );
                        $updated > 0 ? $result['localesFixed'] += $updated : $result['failed']++;
                        break;

                    case Finding::REASON_REVIEW_REVISION:
                        $deleted = $this->gateway->deleteReviewRevisionFile($finding->pk);
                        $deleted > 0 ? $result['reviewFilesDeleted'] += $deleted : $result['failed']++;
                        break;

                    default:
                        $result['skipped']++;
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                $this->warnings[] = sprintf(
                    'Fix failed for %s (pk=%s, %s): %s',
                    $finding->table,
                    (string) $finding->pk,
                    $finding->reason,
                    $e->getMessage()
                );
            }
        }

        foreach ($entityCleaner->getWarnings() as $w) {
            $this->warnings[] = $w;
        }

        return $result;
    }

    /**
     * @param array{orphansDeleted:int, orphanFilesDeleted:int, entityReferencesRecovered:int, entityOrphansFixed:int, localesFixed:int, reviewFilesDeleted:int, journalRecordsDeleted:int, alreadyRemoved:int, skipped:int, failed:int} $result
     */
    private function fixBulkFinding(Finding $finding, OrphanReferenceCleaner $entityCleaner, array &$result): bool
    {
        $pk = (string) $finding->pk;
        if (strpos($pk, Finding::BULK_PREFIX . 'orphan:') === 0) {
            $parts = explode(':', substr($pk, strlen(Finding::BULK_PREFIX . 'orphan:')));
            if (count($parts) !== 4) {
                return false;
            }
            [$fkCol, $parentTable, $parentCol, $ignoreZeroFlag] = $parts;
            $deleted = $this->gateway->deleteOrphanSettings(
                $finding->table,
                $fkCol,
                $parentTable,
                $parentCol,
                $ignoreZeroFlag === '1'
            );
            if ($deleted > 0) {
                $result['orphansDeleted'] += $deleted;
            } elseif ($finding->rowCount > 0) {
                $result['failed']++;
            }
            return true;
        }
        if ($pk === Finding::bulkPk('issueId')) {
            $deleted = $this->gateway->deleteInvalidPublicationIssueIdSettings();
            if ($deleted > 0) {
                $result['orphansDeleted'] += $deleted;
            } elseif ($finding->rowCount > 0) {
                $result['failed']++;
            }
            return true;
        }
        if ($pk === Finding::bulkPk('locale-schema') || $pk === Finding::bulkPk('locale-heuristic')) {
            $names = $finding->entityId !== null && $finding->entityId !== ''
                ? explode('|', (string) $finding->entityId)
                : [];
            $locale = $finding->suggestedLocale !== '' ? $finding->suggestedLocale : $this->defaultLocale;
            $updated = $this->gateway->fixEmptyLocales($finding->table, $names, $locale);
            if ($updated > 0) {
                $result['localesFixed'] += $updated;
            } elseif ($finding->rowCount > 0) {
                $result['failed']++;
            }
            return true;
        }
        if ($pk === Finding::bulkPk('review')) {
            $deleted = $this->gateway->deleteAllReviewRevisionFiles();
            if ($deleted > 0) {
                $result['reviewFilesDeleted'] += $deleted;
            } elseif ($finding->rowCount > 0) {
                $result['failed']++;
            }
            return true;
        }
        return false;
    }

    /**
     * @param Finding[] $journalFindings
     * @param array{failed:int} $result
     */
    private function deleteDeadJournals(array $journalFindings, array &$result): int
    {
        if ($this->cascadeRegistry === null) {
            $this->warnings[] = 'Deleted-journal findings skipped: no cascade registry supplied';
            $result['failed'] += count($journalFindings);
            return 0;
        }

        $journalIds = [];
        if ($this->cascadeRegistry !== null) {
            try {
                foreach ($this->gateway->findDeadJournalIds(
                    $this->cascadeRegistry->getDirectRootColumns()
                ) as $journalId) {
                    $journalIds[(int) $journalId] = true;
                }
            } catch (\Throwable $e) {
                $this->warnings[] = 'Could not resolve dead journal ids: ' . $e->getMessage();
            }
        }
        if (empty($journalIds)) {
            foreach ($journalFindings as $f) {
                if ($f->entityId !== null) {
                    $journalIds[(int) $f->entityId] = true;
                }
            }
        }

        $forwardPlan = $this->cascadeRegistry->build();
        $plan = array_reverse($forwardPlan);
        $planByTable = [];
        foreach ($forwardPlan as $planStep) {
            $planByTable[$planStep['table']] = $planStep;
        }
        $totalDeleted = 0;

        foreach (array_keys($journalIds) as $journalId) {
            try {
                $totalDeleted += $this->gateway->runInTransaction(function () use ($plan, $planByTable, $journalId) {
                    $deleted = 0;
                    foreach ($plan as $step) {
                        if ($step['table'] === 'submission_files' && $step['column'] === 'submission_id') {
                            $deleted += $this->gateway->deleteSubmissionFileDependentsForJournal($journalId);
                        }
                        $deleted += $this->gateway->deleteRowsByDeadJournalPath($step, $planByTable, $journalId);
                    }
                    return $deleted;
                });
            } catch (\Throwable $e) {
                $rows = 0;
                foreach ($forwardPlan as $step) {
                    if ($step['source'] !== 'journal') {
                        continue;
                    }
                    $rows += $this->gateway->countRowsByColumn(
                        $step['table'],
                        $step['column'],
                        [$journalId],
                        $step['assocType']
                    );
                }
                $result['failed'] += $rows > 0 ? $rows : 1;
                $this->warnings[] = sprintf('Cascade rolled back for journal %d: %s', $journalId, $e->getMessage());
            }
        }

        return $totalDeleted;
    }

    /** @return array<string, array<int|string>> */
    private function resolveCascadeIds(array $forwardPlan, int $journalId): array
    {
        $idsByTable = [];
        foreach ($forwardPlan as $step) {
            if ($step['source'] === 'journal') {
                if (!empty($step['aggregate'])) {
                    $ids = [$journalId];
                } else {
                    $ids = $this->gateway->findRowIdsByColumn(
                        $step['table'],
                        $step['identity'],
                        $step['column'],
                        [$journalId],
                        $step['assocType']
                    );
                }
            } else {
                $parentIds = $idsByTable[$step['parent']] ?? [];
                if (empty($parentIds)) {
                    continue;
                }
                $ids = $this->gateway->findRowIdsByColumn(
                    $step['table'],
                    $step['identity'],
                    $step['column'],
                    $parentIds,
                    $step['assocType']
                );
            }
            if (!empty($ids)) {
                $idsByTable[$step['table']] = $ids;
            }
        }
        return $idsByTable;
    }

    private function settingRowAlreadyGone(Finding $finding): bool
    {
        return $this->gateway->deleteSettingRow(
            $finding->table,
            $finding->pk,
            $finding->settingName,
            $finding->locale
        ) === 0;
    }

    /** @return string[] */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
