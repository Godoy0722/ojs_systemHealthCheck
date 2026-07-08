<?php

/**
 * @file tools/SettingsHealthCheck/IlluminateDatabaseGateway.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class IlluminateDatabaseGateway
 *
 * @brief Real DatabaseGateway impl using Illuminate's Capsule manager. Supports
 *        MySQL/MariaDB and PostgreSQL via information_schema. Falls back to
 *        OJS config for the database name when the manager reports it empty.
 *
 *        OJS 3.3 / PHP 7.4 port: the Illuminate DB facade has no root in 3.3, so
 *        we drive the global Capsule manager (set up by PKPApplication) directly.
 */

namespace APP\tools\settingsHealthCheck\src;

use Illuminate\Database\Capsule\Manager as Capsule;

final class IlluminateDatabaseGateway implements DatabaseGateway
{
    /** @var array<string, array{pk:?string, fk:?string}> */
    private array $tableMetaCache = [];

    public function getDatabaseName(): string
    {
        try {
            $name = (string) Capsule::connection()->getDatabaseName();
            if ($name !== '') {
                return $name;
            }
        } catch (\Throwable $e) {
            // fall through to config
        }
        return (string) \Config::getVar('database', 'name');
    }

    public function getSitePrimaryLocale(): string
    {
        try {
            $row = Capsule::table('site')->select('primary_locale')->first();
            $value = is_object($row) ? ($row->primary_locale ?? null) : null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return 'en';
    }

    /**
     * @return string[]
     */
    public function discoverSettingsTables(): array
    {
        $db = $this->getDatabaseName();
        if ($db === '') {
            return [];
        }
        try {
            $rows = Capsule::select(
                'SELECT DISTINCT table_name AS name FROM information_schema.columns'
                . ' WHERE table_schema = ? AND table_name LIKE ? AND column_name = ?',
                [$db, '%\_settings', 'locale']
            );
        } catch (\Throwable $e) {
            return [];
        }
        $names = [];
        foreach ($rows as $r) {
            $value = is_object($r) ? ($r->name ?? $r->NAME ?? null) : null;
            if (is_string($value) && $value !== '') {
                $names[$value] = true;
            }
        }
        return array_keys($names);
    }

    public function getMultilingualOffenders(string $table, array $settingNames): iterable
    {
        if (empty($settingNames) || !$this->tableExists($table)) {
            return;
        }
        $meta = $this->getTableMeta($table);
        if ($meta['pk'] === null) {
            return;
        }
        yield from $this->fetchOffenders($table, $settingNames, $meta);
    }

    /**
     * @return string[]
     */
    public function findSuspectSettingNames(string $table): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }
        try {
            $rows = Capsule::table($table)
                ->select('setting_name')
                ->groupBy('setting_name')
                ->havingRaw(
                    "SUM(CASE WHEN locale = '' OR locale IS NULL THEN 1 ELSE 0 END) > 0"
                    . " AND SUM(CASE WHEN locale <> '' AND locale IS NOT NULL THEN 1 ELSE 0 END) > 0"
                )
                ->get();
        } catch (\Throwable $e) {
            return [];
        }
        return array_map(function ($r) {
            return (string) $r->setting_name;
        }, $rows->all());
    }

    public function getEmptyLocaleRowsForSettings(string $table, array $settingNames): iterable
    {
        if (empty($settingNames) || !$this->tableExists($table)) {
            return;
        }
        yield from $this->fetchOffenders($table, $settingNames, $this->getTableMeta($table));
    }

    public function getForeignKey(string $settingsTable): ?array
    {
        if (!$this->tableExists($settingsTable)) {
            return null;
        }
        $db = $this->getDatabaseName();
        if ($db !== '') {
            try {
                $rows = Capsule::select(
                    'SELECT column_name AS col, referenced_table_name AS parent_table, referenced_column_name AS parent_col'
                    . ' FROM information_schema.key_column_usage'
                    . ' WHERE table_schema = ? AND table_name = ? AND referenced_table_name IS NOT NULL'
                    . ' ORDER BY ordinal_position LIMIT 1',
                    [$db, $settingsTable]
                );
                foreach ($rows as $r) {
                    $col = is_object($r) ? (string) ($r->col ?? $r->COL ?? '') : '';
                    $parentTable = is_object($r) ? (string) ($r->parent_table ?? $r->PARENT_TABLE ?? '') : '';
                    $parentCol = is_object($r) ? (string) ($r->parent_col ?? $r->PARENT_COL ?? '') : '';
                    if ($col !== '' && $parentTable !== '' && $parentCol !== '') {
                        return ['column' => $col, 'parentTable' => $parentTable, 'parentColumn' => $parentCol];
                    }
                }
            } catch (\Throwable $e) {
                // fall through to convention
            }
        }
        $meta = $this->getTableMeta($settingsTable);
        if ($meta['fk'] === null) {
            return null;
        }
        // The parent PK is conventionally named the same as the FK column
        // (journals.journal_id, controlled_vocab_entries.controlled_vocab_entry_id).
        foreach ($this->guessParentTables($meta['fk']) as $parentTable) {
            if ($this->tableExists($parentTable)) {
                return ['column' => $meta['fk'], 'parentTable' => $parentTable, 'parentColumn' => $meta['fk']];
            }
        }
        return null;
    }

    public function findOrphans(string $settingsTable, string $fkCol, string $parentTable, string $parentCol): iterable
    {
        if (!$this->tableExists($settingsTable) || !$this->tableExists($parentTable)) {
            return;
        }
        $meta = $this->getTableMeta($settingsTable);
        if ($meta['pk'] === null) {
            return;
        }
        $pkCol = $meta['pk'];
        try {
            $cursor = Capsule::table($settingsTable . ' as s')
                ->leftJoin($parentTable . ' as p', 's.' . $fkCol, '=', 'p.' . $parentCol)
                ->whereNull('p.' . $parentCol)
                ->whereNotNull('s.' . $fkCol)
                ->select([
                    's.' . $pkCol . ' as pk',
                    's.' . $fkCol . ' as fk',
                    's.setting_name',
                    's.locale',
                    's.setting_value',
                ])
                ->orderBy('s.' . $pkCol)
                ->cursor();
        } catch (\Throwable $e) {
            return;
        }
        foreach ($cursor as $row) {
            yield [
                'pk' => $row->pk,
                'fk' => $row->fk,
                'setting_name' => (string) ($row->setting_name ?? ''),
                'locale' => $row->locale,
                'setting_value' => $row->setting_value,
            ];
        }
    }

    public function filterNullableColumns(string $table, array $candidateColumns): array
    {
        if (empty($candidateColumns) || !$this->tableExists($table)) {
            return [];
        }
        $db = $this->getDatabaseName();
        if ($db === '') {
            return [];
        }
        try {
            $placeholders = implode(',', array_fill(0, count($candidateColumns), '?'));
            $rows = Capsule::select(
                'SELECT column_name AS name FROM information_schema.columns'
                . ' WHERE table_schema = ? AND table_name = ? AND is_nullable = ?'
                . ' AND column_name IN (' . $placeholders . ')',
                array_merge([$db, $table, 'YES'], array_values($candidateColumns))
            );
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $name = is_object($r) ? (string) ($r->name ?? $r->NAME ?? '') : '';
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }

    public function findRowsWithNullColumn(string $table, string $pk, string $column): iterable
    {
        if (!$this->tableExists($table)) {
            return;
        }
        try {
            $cursor = Capsule::table($table)
                ->select([$pk . ' as pk'])
                ->whereNull($column)
                ->orderBy($pk)
                ->cursor();
        } catch (\Throwable $e) {
            return;
        }
        foreach ($cursor as $row) {
            yield ['pk' => $row->pk, 'column' => $column];
        }
    }

    public function findRowsWithNullSettingValue(string $settingsTable): iterable
    {
        if (!$this->tableExists($settingsTable)) {
            return;
        }
        $meta = $this->getTableMeta($settingsTable);
        $pkCol = $meta['pk'];
        $fkCol = $meta['fk'];
        if ($pkCol === null) {
            return;
        }
        $select = [$pkCol . ' as pk', 'setting_name', 'locale', 'setting_value'];
        if ($fkCol !== null) {
            $select[] = $fkCol . ' as fk';
        }
        try {
            $cursor = Capsule::table($settingsTable)
                ->select($select)
                ->whereNull('setting_value')
                ->orderBy($pkCol)
                ->cursor();
        } catch (\Throwable $e) {
            return;
        }
        foreach ($cursor as $row) {
            yield [
                'pk' => $row->pk,
                'fk' => $fkCol === null ? null : ($row->fk ?? null),
                'setting_name' => (string) ($row->setting_name ?? ''),
                'locale' => $row->locale,
                'setting_value' => $row->setting_value,
            ];
        }
    }

    public function deleteSettingRow(string $table, $pk, string $settingName, ?string $locale): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        $query = $this->buildRowQuery($table, $pk, $settingName, $locale);
        if ($query === null) {
            return 0;
        }
        return (int) $query->delete();
    }

    public function setSettingRowLocale(string $table, $pk, string $settingName, ?string $oldLocale, string $newLocale): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        $query = $this->buildRowQuery($table, $pk, $settingName, $oldLocale);
        if ($query === null) {
            return 0;
        }
        return (int) $query->update(['locale' => $newLocale]);
    }

    /**
     * Builds a query scoped to exactly the offending row(s). Surrogate-key tables
     * are pinned by primary key alone; composite-key tables (OJS 3.3) add
     * setting_name and a locale clause, since the anchor id repeats per row.
     *
     * @param int|string $pk
     * @return \Illuminate\Database\Query\Builder|null null when the table has no usable key
     */
    private function buildRowQuery(string $table, $pk, string $settingName, ?string $locale)
    {
        $meta = $this->getTableMeta($table);
        $pkCol = $meta['pk'];
        if ($pkCol === null) {
            return null;
        }
        $query = Capsule::table($table)->where($pkCol, $pk);
        if ($pkCol === $meta['fk']) {
            // Composite key: the anchor id is not unique on its own.
            $query->where('setting_name', $settingName);
            $this->applyLocaleClause($query, $locale);
        }
        return $query;
    }

    /**
     * Matches the offending locale. An empty string and NULL are treated as the
     * same "missing locale" bucket the scanner flagged.
     *
     * @param \Illuminate\Database\Query\Builder $query
     */
    private function applyLocaleClause($query, ?string $locale): void
    {
        if ($locale === null || $locale === '') {
            $query->where(function ($q) {
                $q->whereNull('locale')->orWhere('locale', '');
            });
            return;
        }
        $query->where('locale', $locale);
    }

    /**
     * Candidate parent table names for an entity FK column, best guess first.
     * Handles regular and common irregular OJS plurals
     * (journal_id->journals, controlled_vocab_entry_id->controlled_vocab_entries).
     *
     * @return string[]
     */
    private function guessParentTables(string $fkCol): array
    {
        if (substr($fkCol, -3) !== '_id') {
            return [];
        }
        $stem = substr($fkCol, 0, -3);
        $candidates = [];
        if (substr($stem, -1) === 'y') {
            $candidates[] = substr($stem, 0, -1) . 'ies';
        }
        $candidates[] = $stem . 's';
        $candidates[] = $stem . 'es';
        $candidates[] = $stem;
        return array_values(array_unique($candidates));
    }

    /**
     * @param array{pk:?string,fk:?string} $meta
     */
    private function fetchOffenders(string $table, array $settingNames, array $meta): iterable
    {
        $pkCol = $meta['pk'];
        $fkCol = $meta['fk'];
        if ($pkCol === null) {
            return;
        }
        $select = [$pkCol . ' as pk', 'setting_name', 'locale', 'setting_value'];
        if ($fkCol !== null) {
            $select[] = $fkCol . ' as fk';
        }
        try {
            $cursor = Capsule::table($table)
                ->select($select)
                ->whereIn('setting_name', $settingNames)
                ->where(function ($q) {
                    $q->where('locale', '')->orWhereNull('locale');
                })
                ->orderBy($pkCol)
                ->cursor();
        } catch (\Throwable $e) {
            return;
        }
        foreach ($cursor as $row) {
            yield [
                'pk' => $row->pk,
                'fk' => $fkCol === null ? null : ($row->fk ?? null),
                'setting_name' => (string) $row->setting_name,
                'locale' => $row->locale,
                'setting_value' => $row->setting_value,
            ];
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return Capsule::schema()->hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array{pk:?string,fk:?string}
     */
    private function getTableMeta(string $table): array
    {
        if (isset($this->tableMetaCache[$table])) {
            return $this->tableMetaCache[$table];
        }
        $db = $this->getDatabaseName();
        $rows = [];
        if ($db !== '') {
            try {
                $rows = Capsule::select(
                    'SELECT column_name AS name, column_key AS k FROM information_schema.columns'
                    . ' WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
                    [$db, $table]
                );
            } catch (\Throwable $e) {
                $rows = [];
            }
        }

        // assoc_type/assoc_id are polymorphic columns, not a clean entity FK.
        $reserved = ['setting_name', 'locale', 'setting_value', 'setting_type', 'assoc_type', 'assoc_id'];
        $allNames = [];
        $priNames = [];
        foreach ($rows as $r) {
            $name = is_object($r) ? (string) ($r->name ?? $r->NAME ?? '') : '';
            $key = is_object($r) ? (string) ($r->k ?? $r->K ?? '') : '';
            if ($name === '') {
                continue;
            }
            $allNames[] = $name;
            if ($key === 'PRI') {
                $priNames[] = $name;
            }
        }

        $pk = null;
        $fk = null;

        if (count($priNames) === 1 && substr($priNames[0], -3) === '_id') {
            // OJS 3.5-style: a single surrogate primary key (e.g. journal_setting_id)
            // plus a separate entity foreign-key column.
            $pk = $priNames[0];
            foreach ($allNames as $name) {
                if ($name !== $pk && !in_array($name, $reserved, true) && substr($name, -3) === '_id') {
                    $fk = $name;
                    break;
                }
            }
            if ($fk === null) {
                foreach ($allNames as $name) {
                    if ($name !== $pk && !in_array($name, $reserved, true)) {
                        $fk = $name;
                        break;
                    }
                }
            }
        } else {
            // OJS 3.3-style: composite primary key (entity_id, locale, setting_name[, assoc_*])
            // with no surrogate column. The entity id is both the row anchor (for ordering)
            // and the FK to the parent table. Postgres reports no COLUMN_KEY, so fall back
            // to every column in declaration order.
            $candidates = !empty($priNames) ? $priNames : $allNames;
            foreach ($candidates as $name) {
                if (!in_array($name, $reserved, true) && substr($name, -3) === '_id') {
                    $fk = $name;
                    break;
                }
            }
            $pk = $fk;
            if ($pk === null && !empty($candidates)) {
                $pk = $candidates[0];
            }
        }

        return $this->tableMetaCache[$table] = ['pk' => $pk, 'fk' => $fk];
    }

    public function findReviewRevisionFiles(): iterable
    {
        if (!$this->tableExists('submission_files')) {
            return;
        }
        try {
            $cursor = Capsule::table('submission_files')
                ->select(['submission_file_id as pk', 'submission_id as fk'])
                ->where('file_stage', '=', 15) // SUBMISSION_FILE_REVIEW_REVISION
                ->orderBy('submission_file_id')
                ->cursor();
        } catch (\Throwable $e) {
            return;
        }
        foreach ($cursor as $row) {
            yield [
                'pk' => $row->pk,
                'fk' => $row->fk,
                'setting_name' => 'file_stage',
                'locale' => null,
                'setting_value' => '15',
            ];
        }
    }

    public function deleteReviewRevisionFile($submissionFileId): int
    {
        if (!$this->tableExists('submission_files')) {
            return 0;
        }

        $revisions = Capsule::table('submission_file_revisions')
            ->where('submission_file_id', $submissionFileId)
            ->get(['file_id']);

        foreach ($revisions as $revision) {
            $fileId = $revision->file_id;
            $otherRefs = Capsule::table('submission_file_revisions')
                ->where('file_id', $fileId)
                ->where('submission_file_id', '!=', $submissionFileId)
                ->count();
            if ($otherRefs === 0) {
                try {
                    \Services::get('file')->delete($fileId);
                } catch (\Throwable $e) {
                    // Even if file deletion fails, remove revision link to avoid blocking DB cleanup
                    Capsule::table('submission_file_revisions')
                        ->where('submission_file_id', $submissionFileId)
                        ->where('file_id', $fileId)
                        ->delete();
                }
            } else {
                Capsule::table('submission_file_revisions')
                    ->where('submission_file_id', $submissionFileId)
                    ->where('file_id', $fileId)
                    ->delete();
            }
        }

        Capsule::table('submission_file_settings')
            ->where('submission_file_id', $submissionFileId)
            ->delete();

        Capsule::table('review_round_files')
            ->where('submission_file_id', $submissionFileId)
            ->delete();

        Capsule::table('review_files')
            ->where('submission_file_id', $submissionFileId)
            ->delete();

        if (defined('ASSOC_TYPE_SUBMISSION_FILE')) {
            Capsule::table('notes')
                ->where('assoc_type', ASSOC_TYPE_SUBMISSION_FILE)
                ->where('assoc_id', $submissionFileId)
                ->delete();
        }

        return (int) Capsule::table('submission_files')
            ->where('submission_file_id', $submissionFileId)
            ->delete();
    }
}
