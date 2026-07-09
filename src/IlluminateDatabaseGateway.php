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
 * @brief Database access layer using Illuminate's Capsule manager. Supports
 *        MySQL/MariaDB and PostgreSQL via information_schema. Falls back to
 *        OJS config for the database name when the manager reports it empty.
 *
 *        OJS 3.3 / PHP 7.4 port: the Illuminate DB facade has no root in 3.3, so
 *        we drive the global Capsule manager (set up by PKPApplication) directly.
 */

namespace APP\tools\settingsHealthCheck\src;

use Illuminate\Database\Capsule\Manager as Capsule;

final class IlluminateDatabaseGateway
{
    /** @var array<string, array{pk:?string, fk:?string}> */
    private array $tableMetaCache = [];

    /**
     * Returns the current connection's database name. Falls back to the OJS
     * config when the Capsule manager reports an empty string.
     */
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

    /**
     * Reads the site's primary locale from the `site` table. Falls back to
     * 'en' when the table is missing or the value is empty.
     */
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
     * Queries information_schema for every table matching '%_settings' that
     * has a 'locale' column. Returns deduplicated table names.
     *
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
                $names[] = $value;
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Yields rows from a schema-mapped settings table whose locale is empty
     * or NULL for the given multilingual setting names (Pass A).
     *
     * @param string $table Settings table name
     * @param string[] $settingNames Multilingual setting_names to check
     * @return iterable<array{pk:mixed, fk:mixed|null, setting_name:string, locale:string|null, setting_value:string|null}>
     */
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
     * Auto-discovers setting names that have both localized and non-localized
     * rows within the same table (mixed-locale pattern used by Pass B).
     *
     * @param string $table Settings table name
     * @return string[] Suspect setting_name values
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

    /**
     * Yields rows for the given suspect setting names whose locale is empty
     * or NULL (Pass B continuation).
     *
     * @param string $table Settings table name
     * @param string[] $settingNames Suspect setting names from findSuspectSettingNames()
     * @return iterable<array{pk:mixed, fk:mixed|null, setting_name:string, locale:string|null, setting_value:string|null}>
     */
    public function getEmptyLocaleRowsForSettings(string $table, array $settingNames): iterable
    {
        if (empty($settingNames) || !$this->tableExists($table)) {
            return;
        }
        yield from $this->fetchOffenders($table, $settingNames, $this->getTableMeta($table));
    }

    /**
     * Resolves the foreign-key relationship for a settings table. Uses
     * information_schema.key_column_usage first; falls back to naming
     * conventions when the schema has no declared FK constraint.
     *
     * @param string $settingsTable Settings table name
     * @return array{column:string,parentTable:string,parentColumn:string}|null FK info, or null when unresolvable
     */
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

    /**
     * Yields rows from a settings table whose FK value has no matching row
     * in the parent table (Pass C — orphan detection).
     *
     * @param string $settingsTable Settings table name
     * @param string $fkCol FK column name
     * @param string $parentTable Parent (entity) table name
     * @param string $parentCol Parent PK column name
     * @return iterable<array{pk:mixed, fk:mixed, setting_name:string, locale:string|null, setting_value:string|null}>
     */
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

    /**
     * Given a list of required column names, returns the subset that are
     * declared nullable in the information_schema (used by Pass D1).
     *
     * @param string $table Table name
     * @param string[] $candidateColumns Column names to check
     * @return string[] Subset of columns that are nullable
     */
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

    /**
     * Yields primary-key values for rows where a specific column is NULL
     * (Pass D1 — required-but-null on main entity table).
     *
     * @param string $table Table name
     * @param string $pk Primary-key column name
     * @param string $column Nullable required column to check
     * @return iterable<array{pk:mixed, column:string}>
     */
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

    /**
     * Yields rows from a *_settings table where setting_value IS NULL
     * (Pass D2 — NULL setting_value).
     *
     * @param string $settingsTable Settings table name
     * @return iterable<array{pk:mixed, fk:mixed|null, setting_name:string, locale:string|null, setting_value:null}>
     */
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

    /**
     * Deletes a single settings row, scoped by primary key, setting_name,
     * and locale to avoid collateral damage on composite-key tables.
     *
     * @param string $table Settings table name
     * @param int|string $pk Primary-key value
     * @param string $settingName
     * @param string|null $locale
     * @return int Number of rows deleted
     */
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

    /**
     * Sets the locale on a single settings row to the given replacement.
     *
     * @param string $table Settings table name
     * @param int|string $pk Primary-key value
     * @param string $settingName
     * @param string|null $oldLocale Current (empty) locale to match
     * @param string $newLocale Replacement locale
     * @return int Number of rows updated
     */
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
     * Builds an Illuminate query scoped to exactly one offending row.
     * Surrogate-key tables (OJS 3.5) are pinned by PK alone;
     * composite-key tables (OJS 3.3) add setting_name and locale clauses
     * since the entity id repeats per row.
     *
     * @param string $table Settings table name
     * @param int|string $pk Primary-key value
     * @param string $settingName
     * @param string|null $locale
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
     * Adds a WHERE clause matching the offending locale. An empty string
     * and NULL are treated as the same "missing locale" bucket the scanner
     * flagged.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param string|null $locale
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
     * Generates candidate parent-table names from an FK column, best guess
     * first. Handles regular plurals and common OJS irregulars
     * (e.g. journal_id → journals, controlled_vocab_entry_id →
     * controlled_vocab_entries).
     *
     * @param string $fkCol FK column name ending in '_id'
     * @return string[] Candidate parent-table names
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
     * Shared cursor loop for Pass A and Pass B: queries a settings table
     * for rows with empty/NULL locale on the given setting names, ordered
     * by primary key.
     *
     * @param string $table Settings table name
     * @param string[] $settingNames Setting names to filter on
     * @param array{pk:?string,fk:?string} $meta Cached table metadata
     * @return iterable<array{pk:mixed, fk:mixed|null, setting_name:string, locale:string|null, setting_value:string|null}>
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

    /**
     * Quick existence check against the Illuminate schema builder.
     *
     * @param string $table
     * @return bool
     */
    private function tableExists(string $table): bool
    {
        try {
            return Capsule::schema()->hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Introspects a table's column list from information_schema and resolves
     * the primary-key and foreign-key columns. Handles both OJS 3.5-style
     * (surrogate PK + separate FK) and OJS 3.3-style (composite key where
     * the entity id is both PK and FK). Results are cached per table.
     *
     * @param string $table Table name
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

    /**
     * Yields synthetic rows for submission_files stuck in REVIEW_REVISION
     * status (file_stage = 15). These rows block journal/submission deletion
     * in OJS CLI with a fatal error (Pass E).
     *
     * @return iterable<array{pk:int, fk:int, setting_name:string, locale:null, setting_value:string}>
     */
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

    /**
     * Cascade-deletes a submission_file row stuck in REVIEW_REVISION status
     * along with its revisions, settings, review-round associations, review
     * files, and notes. Attempts physical file deletion via the OJS file
     * service but falls back to DB-only cleanup when that fails.
     *
     * @param int $submissionFileId
     * @return int Number of submission_files rows deleted (0 or 1)
     */
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
