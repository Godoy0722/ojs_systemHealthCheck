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
 * @brief Applies the basic remediations for the findings the Scanner produced.
 *        WRITES to the database — only invoked when --fix is passed.
 *
 *        - Deleted-journal rows    -> the whole cascade is deleted, deepest
 *                                     table first, one transaction per journal.
 *        - Orphaned settings       -> the dangling row is deleted.
 *        - Missing-locale settings -> the row is stamped with the default locale.
 *        - Review-revision files   -> file and database rows are cascade-deleted.
 *        - Empty-field findings    -> left untouched (no safe automatic fix yet).
 *
 *        Deleted-journal findings are processed first, so an orphaned settings
 *        row that the cascade already removed is counted as alreadyRemoved
 *        rather than as a failure.
 *
 *        Each unit is fixed independently; a failure on one is recorded as a
 *        warning and does not abort the rest of the run.
 */

namespace APP\tools\settingsHealthCheck\src;

final class Fixer
{
    /** @var IlluminateDatabaseGateway */
    private $gateway;

    /** @var JournalCascadeRegistry|null */
    private $cascadeRegistry;

    /** @var string Locale stamped onto missing-locale rows that carry no suggestion. */
    private string $defaultLocale;

    /** @var string[] */
    private array $warnings = [];

    /**
     * @brief Resolves the site primary locale once so every fix uses the same
     *        fallback. The cascade registry is required only for
     *        deleted-journal findings.
     */
    public function __construct(IlluminateDatabaseGateway $gateway, ?JournalCascadeRegistry $cascadeRegistry = null)
    {
        $this->gateway = $gateway;
        $this->cascadeRegistry = $cascadeRegistry;
        $locale = $gateway->getSitePrimaryLocale();
        $this->defaultLocale = $locale !== '' ? $locale : 'en';
    }

    /**
     * Applies remediations to every finding. Deleted-journal findings run
     * first, as a cascade per journal inside one transaction each. The
     * remaining findings are then fixed row by row: orphaned rows deleted,
     * missing-locale rows stamped with the default locale, review-revision
     * files cascade-deleted. Empty-field findings are skipped (no safe
     * automatic fix). Each unit is independent — a failure on one does not
     * abort the rest.
     *
     * @param Finding[] $findings
     * @return array{orphansDeleted:int, localesFixed:int, reviewFilesDeleted:int, journalRecordsDeleted:int, alreadyRemoved:int, skipped:int, failed:int}
     */
    public function fix(array $findings): array
    {
        $result = [
            'orphansDeleted' => 0,
            'localesFixed' => 0,
            'reviewFilesDeleted' => 0,
            'journalRecordsDeleted' => 0,
            'alreadyRemoved' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

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

        foreach ($rest as $finding) {
            try {
                switch ($finding->reason) {
                    case Finding::REASON_ORPHAN_ENTITY:
                        $deleted = $this->gateway->deleteSettingRow(
                            $finding->table,
                            $finding->pk,
                            $finding->settingName,
                            $finding->locale
                        );
                        if ($deleted > 0) {
                            $result['orphansDeleted'] += $deleted;
                        } elseif ($this->wasRemovedByCascade($finding)) {
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
                        // Empty-field findings (required NULL / NULL setting_value)
                        // have no safe automatic fix yet.
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

        return $result;
    }

    /**
     * Deletes every leftover row for each dead journal, deepest table first,
     * one transaction per journal. A failure rolls that journal back, is
     * recorded as a warning, and the remaining journals still proceed.
     *
     * @param Finding[] $journalFindings Findings with REASON_DELETED_JOURNAL
     * @param array{failed:int} $result Mutated in place to count failures
     * @return int Total rows deleted across all journals
     */
    private function deleteDeadJournals(array $journalFindings, array &$result): int
    {
        if ($this->cascadeRegistry === null) {
            $this->warnings[] = 'Deleted-journal findings skipped: no cascade registry supplied';
            $result['failed'] += count($journalFindings);
            return 0;
        }

        // journalId => table => [identity values]
        $byJournal = [];
        foreach ($journalFindings as $f) {
            $journalId = (int) $f->entityId;
            $byJournal[$journalId][$f->table][] = $f->pk;
        }

        // Deepest table first: the plan is ordered parents-before-children.
        $plan = array_reverse($this->cascadeRegistry->build());

        $totalDeleted = 0;
        foreach ($byJournal as $journalId => $tables) {
            try {
                $totalDeleted += $this->gateway->runInTransaction(function () use ($plan, $tables, $journalId) {
                    $deleted = 0;
                    foreach ($plan as $step) {
                        $table = $step['table'];
                        if (!isset($tables[$table])) {
                            continue;
                        }

                        if ($step['source'] === 'journal') {
                            // Match the journal column, never the identity
                            // column: a root's identity is not always unique
                            // per journal (plugin_settings is keyed by
                            // plugin_name), so deleting by identity would
                            // cross journal boundaries and destroy live data.
                            $deleted += $this->gateway->deleteRowsByColumn(
                                $table,
                                $step['column'],
                                [$journalId],
                                $step['assocType']
                            );
                            continue;
                        }

                        // Descendants are matched by their FK against the
                        // parent's identity values, which the scan already
                        // resolved and reported.
                        $parentIds = $tables[$step['parent']] ?? [];
                        if (empty($parentIds)) {
                            continue;
                        }
                        $deleted += $this->gateway->deleteRowsByColumn(
                            $table,
                            $step['column'],
                            array_values(array_unique($parentIds)),
                            null
                        );
                    }
                    return $deleted;
                });
            } catch (\Throwable $e) {
                $rows = 0;
                foreach ($tables as $ids) {
                    $rows += count($ids);
                }
                $result['failed'] += $rows;
                $this->warnings[] = sprintf(
                    'Cascade rolled back for journal %d: %s',
                    $journalId,
                    $e->getMessage()
                );
            }
        }

        return $totalDeleted;
    }

    /**
     * True when an orphaned settings row reported zero deletions because the
     * deleted-journal cascade already removed it, rather than because the
     * delete failed. Checked by confirming the row is genuinely gone.
     *
     * @param Finding $finding
     * @return bool
     */
    private function wasRemovedByCascade(Finding $finding): bool
    {
        return $this->gateway->deleteSettingRow(
            $finding->table,
            $finding->pk,
            $finding->settingName,
            $finding->locale
        ) === 0;
    }

    /**
     * Non-fatal errors collected during the fix pass (e.g. one row that
     * couldn't be updated).
     *
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
