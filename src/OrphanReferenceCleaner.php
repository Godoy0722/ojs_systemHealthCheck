<?php

/**
 * @file tools/SettingsHealthCheck/OrphanReferenceCleaner.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OrphanReferenceCleaner
 *
 * @brief Pass H — detect/fix invalid entity references in live journals (LEFT JOIN).
 */

namespace APP\tools\settingsHealthCheck\src;

use Illuminate\Database\Capsule\Manager as Capsule;

final class OrphanReferenceCleaner
{
    public const SCOPE_NONE = 'none';
    public const SCOPE_CONTEXT_ID = 'context_id';
    public const SCOPE_JOURNAL_ID = 'journal_id';
    public const SCOPE_SUBMISSION = 'submission';
    public const SCOPE_PUBLICATION = 'publication';
    public const SCOPE_ISSUE = 'issue';
    public const SCOPE_SUBMISSION_FILE = 'submission_file';
    public const SCOPE_REVIEW = 'review';
    public const SCOPE_QUERY = 'query';
    public const SCOPE_TOMBSTONE = 'tombstone';
    public const SCOPE_SUBSCRIPTION = 'subscription';
    public const SCOPE_NAVIGATION_MENU = 'navigation_menu';
    public const SCOPE_SECTION = 'section';

    private const ID_CHUNK = 500;

    /** @var IlluminateDatabaseGateway */
    private $gateway;

    /** @var string[] */
    private array $warnings = [];

    /** @var int[]|null */
    private $liveJournalIds;

    public function __construct(IlluminateDatabaseGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /** @return Finding[] */
    public function scan(): array
    {
        $findings = [];
        $live = $this->getLiveJournalIds();

        foreach (EntityReferenceRegistry::rules() as $rule) {
            if (!$this->validateRule($rule)) {
                continue;
            }
            try {
                $count = (int) $this->buildInvalidReferenceQuery($rule, $live)->count();
            } catch (\Throwable $e) {
                $this->warnings[] = sprintf('Pass H failed for %s: %s', $rule->ruleKey(), $e->getMessage());
                continue;
            }
            if ($count <= 0) {
                continue;
            }
            $findings[] = new Finding(
                $rule->sourceTable,
                $rule->ruleKey(),
                null,
                $rule->sourceColumn,
                null,
                $rule->referenceTable . '.' . $rule->referenceColumn,
                Finding::REASON_ORPHAN_ENTITY,
                $rule->action,
                $count
            );
        }

        return $findings;
    }

    /** Repoint current_publication_id and section_id before destructive fixes. */
    public function recoverReferences(): int
    {
        $updated = $this->recoverCurrentPublicationIds() + $this->recoverSectionIds();
        return $updated;
    }

    public function fixFinding(Finding $finding): int
    {
        if ($finding->reason !== Finding::REASON_ORPHAN_ENTITY) {
            return 0;
        }
        $rule = EntityReferenceRegistry::findByKey((string) $finding->pk);
        if ($rule === null || !$this->validateRule($rule)) {
            return 0;
        }
        return $this->fixInvalidReferences($rule, $this->getLiveJournalIds());
    }

    /** @return string[] */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    private function recoverCurrentPublicationIds(): int
    {
        if (!$this->tablesExist(['submissions', 'publications', 'journals'])) {
            return 0;
        }

        $updated = 0;
        foreach ($this->invalidCurrentPublicationQuery()->select('s.submission_id', 's.current_publication_id')->get() as $row) {
            $submissionId = (int) $row->submission_id;
            try {
                $newId = Capsule::table('publications')
                    ->where('submission_id', '=', $submissionId)
                    ->orderByDesc('publication_id')
                    ->value('publication_id');
                if ($newId === null) {
                    continue;
                }
                $newId = (int) $newId;
                if ((int) Capsule::table('submissions')->where('submission_id', '=', $submissionId)->update(['current_publication_id' => $newId]) <= 0) {
                    continue;
                }
                $updated++;
                $this->warnings[] = sprintf(
                    'Recovered: submission %d current_publication_id %s → %d',
                    $submissionId,
                    (string) $row->current_publication_id,
                    $newId
                );
            } catch (\Throwable $e) {
                $this->warnings[] = sprintf('Recovery failed for submission %d: %s', $submissionId, $e->getMessage());
            }
        }
        return $updated;
    }

    private function recoverSectionIds(): int
    {
        if (!$this->tablesExist(['publications', 'submissions', 'sections', 'journals'])) {
            return 0;
        }

        $updated = 0;
        foreach ($this->invalidSectionQuery()->select('p.publication_id', 'p.submission_id', 'p.section_id', 'sub.context_id')->get() as $row) {
            $publicationId = (int) $row->publication_id;
            $journalId = (int) $row->context_id;
            try {
                $newSectionId = Capsule::table('sections')
                    ->where('journal_id', '=', $journalId)
                    ->where('is_inactive', '=', 0)
                    ->min('section_id');
                if ($newSectionId === null) {
                    continue;
                }
                $newSectionId = (int) $newSectionId;
                if ((int) Capsule::table('publications')->where('publication_id', '=', $publicationId)->update(['section_id' => $newSectionId]) <= 0) {
                    continue;
                }
                $updated++;
                $this->warnings[] = sprintf(
                    'Recovered: publication %d section_id %s → %d',
                    $publicationId,
                    (string) $row->section_id,
                    $newSectionId
                );
            } catch (\Throwable $e) {
                $this->warnings[] = sprintf('Recovery failed for publication %d: %s', $publicationId, $e->getMessage());
            }
        }
        return $updated;
    }

    private function fixInvalidReferences(EntityReferenceRule $rule, array $liveJournalIds): int
    {
        $ids = $this->buildInvalidReferenceQuery($rule, $liveJournalIds)
            ->distinct()
            ->pluck('s.' . $rule->sourceColumn)
            ->filter(function ($id) {
                return $id !== null;
            })
            ->values()
            ->all();

        if ($rule->action === EntityReferenceRule::ACTION_DELETE_REQUIRED) {
            return $this->deleteByColumnValues($rule, $ids, true);
        }
        if (empty($ids)) {
            return 0;
        }
        if ($rule->action === EntityReferenceRule::ACTION_NULLIFY) {
            $updated = 0;
            foreach (array_chunk($ids, self::ID_CHUNK) as $chunk) {
                $updated += (int) Capsule::table($rule->sourceTable)
                    ->whereIn($rule->sourceColumn, $chunk)
                    ->update([$rule->sourceColumn => null]);
            }
            return $updated;
        }
        return $this->deleteByColumnValues($rule, $ids, false);
    }

    private function deleteByColumnValues(EntityReferenceRule $rule, array $ids, bool $includeNull): int
    {
        $deleted = 0;
        if ($includeNull) {
            $deleted += (int) Capsule::table($rule->sourceTable)
                ->whereNull($rule->sourceColumn)
                ->delete();
        }
        if (empty($ids)) {
            return $deleted;
        }
        foreach (array_chunk($ids, self::ID_CHUNK) as $chunk) {
            $deleted += (int) Capsule::table($rule->sourceTable)
                ->whereIn($rule->sourceColumn, $chunk)
                ->delete();
        }
        return $deleted;
    }

    private function buildInvalidReferenceQuery(EntityReferenceRule $rule, array $liveJournalIds)
    {
        $query = Capsule::table($rule->sourceTable . ' as s')
            ->leftJoin(
                $rule->referenceTable . ' as r',
                's.' . $rule->sourceColumn,
                '=',
                'r.' . $rule->referenceColumn
            )
            ->whereNull('r.' . $rule->referenceColumn);

        if ($rule->action !== EntityReferenceRule::ACTION_DELETE_REQUIRED) {
            $query->whereNotNull('s.' . $rule->sourceColumn);
        }
        if ($rule->ignoreZero) {
            $query->where('s.' . $rule->sourceColumn, '!=', 0);
        }

        $this->applyLiveJournalScope($query, $rule, $liveJournalIds);
        return $query;
    }

    private function invalidCurrentPublicationQuery()
    {
        return Capsule::table('submissions as s')
            ->join('journals as j', 'j.journal_id', '=', 's.context_id')
            ->leftJoin('publications as p', 'p.publication_id', '=', 's.current_publication_id')
            ->whereNotNull('s.current_publication_id')
            ->whereNull('p.publication_id')
            ->whereExists(function ($q) {
                $q->from('publications as p2')
                    ->whereColumn('p2.submission_id', '=', 's.submission_id')
                    ->selectRaw('1');
            });
    }

    private function invalidSectionQuery()
    {
        return Capsule::table('publications as p')
            ->join('submissions as sub', 'sub.submission_id', '=', 'p.submission_id')
            ->join('journals as j', 'j.journal_id', '=', 'sub.context_id')
            ->leftJoin('sections as sec', 'sec.section_id', '=', 'p.section_id')
            ->whereNotNull('p.section_id')
            ->whereNull('sec.section_id')
            ->whereExists(function ($q) {
                $q->from('sections as s')
                    ->whereColumn('s.journal_id', '=', 'sub.context_id')
                    ->where('s.is_inactive', '=', 0)
                    ->selectRaw('1');
            });
    }

    private function applyLiveJournalScope($query, EntityReferenceRule $rule, array $liveJournalIds): void
    {
        $scope = $rule->journalScope;
        $table = $rule->sourceTable;

        if ($scope === self::SCOPE_NONE || empty($liveJournalIds)) {
            return;
        }

        switch ($scope) {
            case self::SCOPE_CONTEXT_ID:
                if ($this->gateway->columnExists($table, 'context_id')) {
                    $query->whereIn('s.context_id', $liveJournalIds);
                }
                break;
            case self::SCOPE_JOURNAL_ID:
                if ($this->gateway->columnExists($table, 'journal_id')) {
                    $query->whereIn('s.journal_id', $liveJournalIds);
                }
                break;
            case self::SCOPE_SUBMISSION:
                $query->join('submissions as shc_sub', 'shc_sub.submission_id', '=', 's.submission_id')
                    ->whereIn('shc_sub.context_id', $liveJournalIds);
                break;
            case self::SCOPE_PUBLICATION:
                if ($table === 'publications') {
                    $query->join('submissions as shc_sub', 'shc_sub.submission_id', '=', 's.submission_id')
                        ->whereIn('shc_sub.context_id', $liveJournalIds);
                } else {
                    $query->join('publications as shc_pub', 'shc_pub.publication_id', '=', 's.publication_id')
                        ->join('submissions as shc_sub', 'shc_sub.submission_id', '=', 'shc_pub.submission_id')
                        ->whereIn('shc_sub.context_id', $liveJournalIds);
                }
                break;
            case self::SCOPE_ISSUE:
                $query->join('issues as shc_iss', 'shc_iss.issue_id', '=', 's.issue_id')
                    ->whereIn('shc_iss.journal_id', $liveJournalIds);
                break;
            case self::SCOPE_SUBMISSION_FILE:
                if ($table === 'submission_files') {
                    $query->join('submissions as shc_sub', 'shc_sub.submission_id', '=', 's.submission_id')
                        ->whereIn('shc_sub.context_id', $liveJournalIds);
                } else {
                    $query->join('submission_files as shc_sf', 'shc_sf.submission_file_id', '=', 's.submission_file_id')
                        ->join('submissions as shc_sub', 'shc_sub.submission_id', '=', 'shc_sf.submission_id')
                        ->whereIn('shc_sub.context_id', $liveJournalIds);
                }
                break;
            case self::SCOPE_REVIEW:
                if ($table === 'review_assignments') {
                    $query->join('submissions as shc_sub', 'shc_sub.submission_id', '=', 's.submission_id')
                        ->whereIn('shc_sub.context_id', $liveJournalIds);
                } else {
                    $query->join('review_assignments as shc_ra', 'shc_ra.review_id', '=', 's.review_id')
                        ->join('submissions as shc_sub', 'shc_sub.submission_id', '=', 'shc_ra.submission_id')
                        ->whereIn('shc_sub.context_id', $liveJournalIds);
                }
                break;
            case self::SCOPE_QUERY:
                $query->join('queries as shc_q', 'shc_q.query_id', '=', 's.query_id')
                    ->join('submissions as shc_sub', function ($join) {
                        $join->on('shc_sub.submission_id', '=', 'shc_q.assoc_id')
                            ->where('shc_q.assoc_type', '=', JournalCascadeRegistry::ASSOC_TYPE_SUBMISSION);
                    })
                    ->whereIn('shc_sub.context_id', $liveJournalIds);
                break;
            case self::SCOPE_TOMBSTONE:
                $query->join('data_object_tombstones as shc_t', 'shc_t.tombstone_id', '=', 's.tombstone_id')
                    ->join('submissions as shc_sub', 'shc_sub.submission_id', '=', 'shc_t.data_object_id')
                    ->whereIn('shc_sub.context_id', $liveJournalIds);
                break;
            case self::SCOPE_SUBSCRIPTION:
                if ($table === 'subscriptions') {
                    $query->whereIn('s.journal_id', $liveJournalIds);
                } else {
                    $query->join('subscriptions as shc_su', 'shc_su.subscription_id', '=', 's.subscription_id')
                        ->whereIn('shc_su.journal_id', $liveJournalIds);
                }
                break;
            case self::SCOPE_NAVIGATION_MENU:
                $query->join('navigation_menus as shc_nm', 'shc_nm.navigation_menu_id', '=', 's.navigation_menu_id')
                    ->whereIn('shc_nm.context_id', $liveJournalIds);
                break;
            case self::SCOPE_SECTION:
                $query->join('sections as shc_sec', 'shc_sec.section_id', '=', 's.section_id')
                    ->whereIn('shc_sec.journal_id', $liveJournalIds);
                break;
        }
    }

    private function validateRule(EntityReferenceRule $rule): bool
    {
        return $this->gateway->tableExists($rule->sourceTable)
            && $this->gateway->tableExists($rule->referenceTable)
            && $this->gateway->columnExists($rule->sourceTable, $rule->sourceColumn)
            && $this->gateway->columnExists($rule->referenceTable, $rule->referenceColumn);
    }

    /** @param string[] $tables */
    private function tablesExist(array $tables): bool
    {
        foreach ($tables as $table) {
            if (!$this->gateway->tableExists($table)) {
                return false;
            }
        }
        return true;
    }

    /** @return int[] */
    private function getLiveJournalIds(): array
    {
        if ($this->liveJournalIds !== null) {
            return $this->liveJournalIds;
        }
        if (!$this->gateway->tableExists('journals')) {
            $this->liveJournalIds = [];
            return $this->liveJournalIds;
        }
        $ids = [];
        try {
            foreach (Capsule::table('journals')->orderBy('journal_id')->pluck('journal_id') as $id) {
                $ids[] = (int) $id;
            }
        } catch (\Throwable $e) {
            $this->warnings[] = 'Pass H: unable to read live journals: ' . $e->getMessage();
        }
        $this->liveJournalIds = $ids;
        return $this->liveJournalIds;
    }
}
