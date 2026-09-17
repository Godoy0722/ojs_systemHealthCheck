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

    /** @param callable(string):void|null $onRule Called with source table name before each rule is scanned. */
    public function scan(?callable $onRule = null): array
    {
        $findings = [];
        $live = $this->getLiveJournalIds();
        $claimed = [];

        foreach (EntityReferenceRegistry::rules() as $rule) {
            if ($onRule !== null) {
                $onRule($rule->sourceTable);
            }
            if (!$this->validateRule($rule)) {
                continue;
            }
            $this->appendMissingRequiredReference($rule, $findings);
            try {
                $count = (int) $this->buildInvalidReferenceQuery($rule, $live)->count();
            } catch (\Throwable $e) {
                $this->warnings[] = sprintf('Pass H failed for %s: %s', $rule->ruleKey(), $e->getMessage());
                continue;
            }
            if ($count <= 0) {
                continue;
            }
            $finding = new Finding(
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
            $identities = $this->collectInvalidIdentities($rule, $live);
            if (!empty($identities)) {
                $finding->uniqueRowCount = Finding::claimIdentities(
                    $claimed,
                    $rule->sourceTable,
                    $identities
                );
            }
            $findings[] = $finding;
        }

        return $findings;
    }

    /**
     * Reports NULLs in a reference column the live schema declares NOT NULL.
     *
     * Such a row is corrupt but it is not a leftover of a deleted parent, so the
     * dangling-reference query excludes it and no fix is offered. The count is
     * site-wide: a row whose scoping reference is itself NULL cannot be attributed
     * to a journal. Columns declared nullable are skipped — NULL is a legitimate
     * value there, and Pass D1 already covers the schema-required ones.
     *
     * @param Finding[] $findings
     */
    private function appendMissingRequiredReference(EntityReferenceRule $rule, array &$findings): void
    {
        if ($rule->action !== EntityReferenceRule::ACTION_DELETE_REQUIRED) {
            return;
        }
        if (!empty($this->gateway->filterNullableColumns($rule->sourceTable, [$rule->sourceColumn]))) {
            return;
        }
        try {
            $count = (int) Capsule::table($rule->sourceTable)
                ->whereNull($rule->sourceColumn)
                ->count();
        } catch (\Throwable $e) {
            $this->warnings[] = sprintf('Pass H (NULL check) failed for %s: %s', $rule->ruleKey(), $e->getMessage());
            return;
        }
        if ($count <= 0) {
            return;
        }
        $findings[] = new Finding(
            $rule->sourceTable,
            Finding::bulkPk('required-null-ref', $rule->sourceColumn),
            null,
            $rule->sourceColumn,
            null,
            null,
            Finding::REASON_REQUIRED_NULL,
            '',
            $count
        );
    }

    /**
     * Repoint current_publication_id and section_id before destructive fixes.
     *
     * @param callable(string):void|null $onStep Called with table name before each recovery pass.
     */
    public function recoverReferences(?callable $onStep = null): int
    {
        if ($onStep !== null) {
            $onStep('submissions');
        }
        $updated = $this->recoverCurrentPublicationIds();
        if ($onStep !== null) {
            $onStep('publications');
        }
        return $updated + $this->recoverSectionIds();
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

    /**
     * Expands an aggregate entity-reference finding into one Finding per offending row.
     *
     * @return Finding[]
     */
    public function expandEntityOrphanFinding(Finding $finding): array
    {
        if ($finding->reason !== Finding::REASON_ORPHAN_ENTITY || !Finding::isEntityOrphan($finding)) {
            return [$finding];
        }
        $rule = EntityReferenceRegistry::findByKey((string) $finding->pk);
        if ($rule === null || !$this->validateRule($rule)) {
            return [$finding];
        }

        $meta = $this->gateway->getTableMetaPublic($rule->sourceTable);
        $pkCol = $meta['pk'] ?? $rule->sourceColumn;
        $select = ['s.' . $pkCol . ' as pk', 's.' . $rule->sourceColumn . ' as fk'];
        if ($this->gateway->columnExists($rule->sourceTable, 'setting_name')) {
            $select[] = 's.setting_name';
        }
        if ($this->gateway->columnExists($rule->sourceTable, 'locale')) {
            $select[] = 's.locale';
        }

        $expanded = [];
        try {
            $cursor = $this->buildInvalidReferenceQuery($rule, $this->getLiveJournalIds())
                ->select($select)
                ->orderBy('s.' . $pkCol)
                ->cursor();
        } catch (\Throwable $e) {
            return [$finding];
        }

        foreach ($cursor as $row) {
            $expanded[] = new Finding(
                $rule->sourceTable,
                $row->pk,
                $row->fk,
                $rule->sourceColumn,
                isset($row->locale) ? (string) $row->locale : null,
                $rule->referenceTable . '.' . $rule->referenceColumn,
                Finding::REASON_ORPHAN_ENTITY,
                $rule->action
            );
        }

        return $expanded !== [] ? $expanded : [$finding];
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
                // Same precedence as PKPSubmissionService::updateStatus(): the latest
                // published publication, falling back to the latest of any status.
                // Ordering by publication_id alone can demote a published article to a
                // newer unpublished draft.
                $newId = Capsule::table('publications')
                    ->where('submission_id', '=', $submissionId)
                    ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [STATUS_PUBLISHED])
                    ->orderByDesc('publication_id')
                    ->value('publication_id');
                if ($newId === null) {
                    $this->warnings[] = sprintf(
                        'Submission %d points at missing publication %s and has no publication left;'
                        . ' OJS requires at least one. Needs manual review.',
                        $submissionId,
                        (string) $row->current_publication_id
                    );
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

    /**
     * Deletes or nullifies the same rows the scan counted: scoped query, then
     * write by the source table's primary key. Never round-trips through the
     * invalid FK value, which would also hit dead-journal rows the scan hid.
     */
    private function fixInvalidReferences(EntityReferenceRule $rule, array $liveJournalIds): int
    {
        $identities = $this->collectInvalidIdentities($rule, $liveJournalIds);
        if (empty($identities)) {
            return 0;
        }

        if ($rule->action === EntityReferenceRule::ACTION_NULLIFY) {
            return $this->updateRowsByIdentity($rule->sourceTable, $identities, [$rule->sourceColumn => null]);
        }
        return $this->deleteRowsByIdentity($rule->sourceTable, $identities);
    }

    /**
     * Primary-key tuples for rows matching the scoped invalid-reference query.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectInvalidIdentities(EntityReferenceRule $rule, array $liveJournalIds): array
    {
        $pkCols = $this->gateway->getPrimaryKeyColumns($rule->sourceTable);
        if (empty($pkCols)) {
            $this->warnings[] = sprintf(
                'Pass H cannot identify rows for %s: table %s has no primary key',
                $rule->ruleKey(),
                $rule->sourceTable
            );
            return [];
        }

        $select = [];
        foreach ($pkCols as $col) {
            $select[] = 's.' . $col . ' as ' . $col;
        }

        try {
            $rows = $this->buildInvalidReferenceQuery($rule, $liveJournalIds)
                ->select($select)
                ->distinct()
                ->get();
        } catch (\Throwable $e) {
            $this->warnings[] = sprintf('Pass H identity query failed for %s: %s', $rule->ruleKey(), $e->getMessage());
            return [];
        }

        $identities = [];
        foreach ($rows as $row) {
            $tuple = [];
            $skip = false;
            foreach ($pkCols as $col) {
                $value = is_object($row) ? ($row->{$col} ?? $row->{strtoupper($col)} ?? null) : null;
                if ($value === null) {
                    $skip = true;
                    break;
                }
                $tuple[$col] = $value;
            }
            if (!$skip) {
                $identities[] = $tuple;
            }
        }
        return $identities;
    }

    /**
     * @param array<int, array<string, mixed>> $identities
     */
    private function deleteRowsByIdentity(string $table, array $identities): int
    {
        $deleted = 0;
        foreach (array_chunk($identities, self::ID_CHUNK) as $chunk) {
            $deleted += (int) $this->identityQuery($table, $chunk)->delete();
        }
        return $deleted;
    }

    /**
     * @param array<int, array<string, mixed>> $identities
     * @param array<string, mixed> $values
     */
    private function updateRowsByIdentity(string $table, array $identities, array $values): int
    {
        $updated = 0;
        foreach (array_chunk($identities, self::ID_CHUNK) as $chunk) {
            $updated += (int) $this->identityQuery($table, $chunk)->update($values);
        }
        return $updated;
    }

    /**
     * @param array<int, array<string, mixed>> $chunk
     * @return \Illuminate\Database\Query\Builder
     */
    private function identityQuery(string $table, array $chunk)
    {
        $query = Capsule::table($table);
        $pkCols = array_keys($chunk[0]);
        if (count($pkCols) === 1) {
            $col = $pkCols[0];
            $ids = [];
            foreach ($chunk as $tuple) {
                $ids[] = $tuple[$col];
            }
            return $query->whereIn($col, $ids);
        }
        $query->where(function ($q) use ($chunk) {
            foreach ($chunk as $tuple) {
                $q->orWhere(function ($q2) use ($tuple) {
                    foreach ($tuple as $col => $val) {
                        $q2->where($col, $val);
                    }
                });
            }
        });
        return $query;
    }

    private function buildInvalidReferenceQuery(EntityReferenceRule $rule, array $liveJournalIds)
    {
        // A NULL source column means "no reference", which cannot be a leftover of a
        // deleted parent. Only a value that points at a missing row is in scope here;
        // NULLs are reported by appendMissingRequiredReference() and never fixed.
        $query = Capsule::table($rule->sourceTable . ' as s')
            ->leftJoin(
                $rule->referenceTable . ' as r',
                's.' . $rule->sourceColumn,
                '=',
                'r.' . $rule->referenceColumn
            )
            ->whereNull('r.' . $rule->referenceColumn)
            ->whereNotNull('s.' . $rule->sourceColumn);

        if ($rule->ignoreZero) {
            $query->where('s.' . $rule->sourceColumn, '!=', 0);
        }

        $this->applyLiveJournalScope($query, $rule, $liveJournalIds);
        return $query;
    }

    /**
     * Submissions in a live journal whose current_publication_id points at a
     * publication that no longer exists. Submissions with no publication at all are
     * intentionally included so recoverCurrentPublicationIds() can report them; it
     * skips them for repointing, since there is nothing to repoint to.
     */
    private function invalidCurrentPublicationQuery()
    {
        return Capsule::table('submissions as s')
            ->join('journals as j', 'j.journal_id', '=', 's.context_id')
            ->leftJoin('publications as p', 'p.publication_id', '=', 's.current_publication_id')
            ->whereNotNull('s.current_publication_id')
            ->whereNull('p.publication_id');
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
