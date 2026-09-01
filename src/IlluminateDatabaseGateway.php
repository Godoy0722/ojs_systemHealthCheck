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
    /** Maximum ids per WHERE IN clause, to keep statements inside driver limits. */
    private const ID_CHUNK = 500;

    /**
     * Tables with an FK to central `files.file_id`. Matches OJS 3.4
     * PreflightCheckMigration::getEntityRelationships():
     *   'files' => ['submission_files', 'submission_file_revisions']
     * Verified on OJS 3.3 via information_schema (only these two FKs).
     *
     * Not included — separate storage, different file_id namespace:
     *   issue_files, library_files, temporary_files, draft_dataset_files
     * Legacy pre-3.3 (file_id + revision, no FK to files):
     *   submission_artwork_files, submission_supplementary_files
     *
     * @var array<int, array{0:string,1:string}>
     */
    private const FILES_REFERENCER_TABLES = [
        ['submission_files', 'file_id'],
        ['submission_file_revisions', 'file_id'],
    ];

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
        return $this->discoverTablesMatching('%\_settings', 'locale');
    }

    /**
     * Every *_settings table present in the live schema (locale optional).
     * Merges information_schema discovery with SettingsFkRegistry so orphan-only
     * tables (event_log_settings, plugin_settings, …) are always considered.
     *
     * @return string[]
     */
    public function discoverAllSettingsTables(): array
    {
        $live = $this->discoverTablesMatching('%\_settings');
        $registered = SettingsFkRegistry::allSettingsTables();
        $names = [];
        foreach (array_merge($live, $registered) as $table) {
            if ($this->tableExists($table) && !SettingsFkRegistry::isExcluded($table)) {
                $names[$table] = true;
            }
        }
        return array_keys($names);
    }

    /**
     * @return string[]
     */
    private function discoverTablesMatching(string $tablePattern, ?string $requiredColumn = null): array
    {
        $db = $this->getDatabaseName();
        if ($db === '') {
            return [];
        }
        try {
            if ($requiredColumn === null) {
                $rows = Capsule::select(
                    'SELECT table_name AS name FROM information_schema.tables'
                    . ' WHERE table_schema = ? AND table_name LIKE ? ORDER BY table_name',
                    [$db, $tablePattern]
                );
            } else {
                $rows = Capsule::select(
                    'SELECT DISTINCT table_name AS name FROM information_schema.columns'
                    . ' WHERE table_schema = ? AND table_name LIKE ? AND column_name = ?',
                    [$db, $tablePattern, $requiredColumn]
                );
            }
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
     * @return string[]
     */
    private function getTableColumns(string $table): array
    {
        $db = $this->getDatabaseName();
        if ($db === '') {
            return [];
        }
        try {
            $rows = Capsule::select(
                'SELECT column_name AS name FROM information_schema.columns'
                . ' WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
                [$db, $table]
            );
        } catch (\Throwable $e) {
            return [];
        }
        $names = [];
        foreach ($rows as $r) {
            $name = is_object($r) ? (string) ($r->name ?? $r->NAME ?? '') : '';
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
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
     * Counts rows with empty/NULL locale on the given setting names.
     */
    public function countEmptyLocaleRows(string $table, array $settingNames): int
    {
        if (empty($settingNames) || !$this->tableExists($table)) {
            return 0;
        }
        $meta = $this->getTableMeta($table);
        if ($meta['pk'] === null) {
            return 0;
        }
        try {
            return (int) Capsule::table($table)
                ->whereIn('setting_name', $settingNames)
                ->where(function ($q) {
                    $q->where('locale', '')->orWhereNull('locale');
                })
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Sets locale on all rows with empty/NULL locale for the given setting names.
     */
    public function fixEmptyLocales(string $table, array $settingNames, string $newLocale): int
    {
        if (empty($settingNames) || !$this->tableExists($table)) {
            return 0;
        }
        try {
            return (int) Capsule::table($table)
                ->whereIn('setting_name', $settingNames)
                ->where(function ($q) {
                    $q->where('locale', '')->orWhereNull('locale');
                })
                ->update(['locale' => $newLocale]);
        } catch (\Throwable $e) {
            return 0;
        }
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
     * Resolves every foreign-key relationship for a settings table. Order:
     * 1) declared DB constraints; 2) SettingsFkRegistry; 3) naming convention.
     *
     * @param string $settingsTable Settings table name
     * @return array<int, array{column:string,parentTable:string,parentColumn:string,ignoreZero?:bool}>
     */
    public function getForeignKeys(string $settingsTable): array
    {
        if (!$this->tableExists($settingsTable) || SettingsFkRegistry::isExcluded($settingsTable)) {
            return [];
        }

        $db = $this->getDatabaseName();
        if ($db !== '') {
            try {
                $rows = Capsule::select(
                    'SELECT column_name AS col, referenced_table_name AS parent_table, referenced_column_name AS parent_col'
                    . ' FROM information_schema.key_column_usage'
                    . ' WHERE table_schema = ? AND table_name = ? AND referenced_table_name IS NOT NULL'
                    . ' ORDER BY ordinal_position',
                    [$db, $settingsTable]
                );
                $fromSchema = [];
                foreach ($rows as $r) {
                    $col = is_object($r) ? (string) ($r->col ?? $r->COL ?? '') : '';
                    $parentTable = is_object($r) ? (string) ($r->parent_table ?? $r->PARENT_TABLE ?? '') : '';
                    $parentCol = is_object($r) ? (string) ($r->parent_col ?? $r->PARENT_COL ?? '') : '';
                    if ($col !== '' && $parentTable !== '' && $parentCol !== '') {
                        $fromSchema[] = [
                            'column' => $col,
                            'parentTable' => $parentTable,
                            'parentColumn' => $parentCol,
                        ];
                    }
                }
                if (!empty($fromSchema)) {
                    return $fromSchema;
                }
            } catch (\Throwable $e) {
                // fall through
            }
        }

        $registryRules = SettingsFkRegistry::rulesFor($settingsTable);
        $resolved = [];
        foreach ($registryRules as $rule) {
            if ($this->tableExists($rule['parentTable'])) {
                $resolved[] = $rule;
            }
        }
        if (!empty($resolved)) {
            return $resolved;
        }

        $meta = $this->getTableMeta($settingsTable);
        if ($meta['fk'] === null) {
            return [];
        }
        foreach ($this->guessParentTables($meta['fk']) as $parentTable) {
            if ($this->tableExists($parentTable)) {
                return [[
                    'column' => $meta['fk'],
                    'parentTable' => $parentTable,
                    'parentColumn' => $meta['fk'],
                ]];
            }
        }
        return [];
    }

    /**
     * First resolvable FK for a settings table (backward-compatible helper).
     *
     * @param string $settingsTable Settings table name
     * @return array{column:string,parentTable:string,parentColumn:string,ignoreZero?:bool}|null
     */
    public function getForeignKey(string $settingsTable): ?array
    {
        $keys = $this->getForeignKeys($settingsTable);
        return empty($keys) ? null : $keys[0];
    }

    /**
     * Yields rows from a settings table whose FK value has no matching row
     * in the parent table (Pass C — orphan detection).
     *
     * @param string $settingsTable Settings table name
     * @param string $fkCol FK column name
     * @param string $parentTable Parent (entity) table name
     * @param string $parentCol Parent PK column name
     * @param bool $ignoreZero When true, FK value 0 is treated as site-wide scope, not an orphan
     * @return iterable<array{pk:mixed, fk:mixed, setting_name:string, locale:string|null, setting_value:string|null}>
     */
    public function findOrphans(
        string $settingsTable,
        string $fkCol,
        string $parentTable,
        string $parentCol,
        bool $ignoreZero = false
    ): iterable {
        $meta = $this->getTableMeta($settingsTable);
        if ($meta['pk'] === null) {
            return;
        }
        $pkCol = $meta['pk'];
        $columns = $this->getTableColumns($settingsTable);
        $select = ['s.' . $pkCol . ' as pk', 's.' . $fkCol . ' as fk'];
        if (in_array('setting_name', $columns, true)) {
            $select[] = 's.setting_name';
        }
        if (in_array('locale', $columns, true)) {
            $select[] = 's.locale';
        }
        if (in_array('setting_value', $columns, true)) {
            $select[] = 's.setting_value';
        }
        $query = $this->buildOrphanQuery($settingsTable, $fkCol, $parentTable, $parentCol, $ignoreZero);
        if ($query === null) {
            return;
        }
        try {
            $cursor = $query->select($select)->orderBy('s.' . $pkCol)->cursor();
        } catch (\Throwable $e) {
            return;
        }
        foreach ($cursor as $row) {
            yield [
                'pk' => $row->pk,
                'fk' => $row->fk,
                'setting_name' => (string) ($row->setting_name ?? ''),
                'locale' => $row->locale ?? null,
                'setting_value' => $row->setting_value ?? null,
            ];
        }
    }

    /**
     * Counts settings rows whose FK value has no matching parent (Pass C).
     */
    public function countOrphans(
        string $settingsTable,
        string $fkCol,
        string $parentTable,
        string $parentCol,
        bool $ignoreZero = false
    ): int {
        $query = $this->buildOrphanQuery($settingsTable, $fkCol, $parentTable, $parentCol, $ignoreZero);
        return $query === null ? 0 : (int) $query->count();
    }

    /**
     * Deletes all orphan settings rows for one FK in a single statement.
     */
    public function deleteOrphanSettings(
        string $settingsTable,
        string $fkCol,
        string $parentTable,
        string $parentCol,
        bool $ignoreZero = false
    ): int {
        if (!$this->tableExists($settingsTable) || !$this->tableExists($parentTable)) {
            return 0;
        }
        $sql = 'DELETE s FROM `' . $settingsTable . '` AS s'
            . ' LEFT JOIN `' . $parentTable . '` AS p ON s.`' . $fkCol . '` = p.`' . $parentCol . '`'
            . ' WHERE p.`' . $parentCol . '` IS NULL AND s.`' . $fkCol . '` IS NOT NULL';
        if ($ignoreZero) {
            $sql .= ' AND s.`' . $fkCol . '` != 0';
        }
        try {
            return (int) Capsule::affectingStatement($sql);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @return \Illuminate\Database\Query\Builder|null
     */
    private function buildOrphanQuery(
        string $settingsTable,
        string $fkCol,
        string $parentTable,
        string $parentCol,
        bool $ignoreZero
    ) {
        if (!$this->tableExists($settingsTable) || !$this->tableExists($parentTable)) {
            return null;
        }
        $meta = $this->getTableMeta($settingsTable);
        if ($meta['pk'] === null) {
            return null;
        }
        try {
            $query = Capsule::table($settingsTable . ' as s')
                ->leftJoin($parentTable . ' as p', 's.' . $fkCol, '=', 'p.' . $parentCol)
                ->whereNull('p.' . $parentCol)
                ->whereNotNull('s.' . $fkCol);
            if ($ignoreZero) {
                $query->where('s.' . $fkCol, '!=', 0);
            }
            return $query;
        } catch (\Throwable $e) {
            return null;
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

    public function countRowsWithNullColumn(string $table, string $column): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        try {
            return (int) Capsule::table($table)->whereNull($column)->count();
        } catch (\Throwable $e) {
            return 0;
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

    public function countRowsWithNullSettingValue(string $table): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        try {
            return (int) Capsule::table($table)->whereNull('setting_value')->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Returns publication_settings rows whose issueId value does not match
     * any live issues.issue_id. Matches OJS 3.4 PreflightCheckMigration.
     *
     * @return \Generator<int, array{publication_id:int|string, submission_id:int|string, setting_value:string, locale:?string}>
     */
    public function findInvalidPublicationIssueIdSettings(): \Generator
    {
        if (!$this->tableExists('publication_settings')
            || !$this->tableExists('publications')
            || !$this->tableExists('issues')
        ) {
            return;
        }
        if (!$this->columnExists('publication_settings', 'setting_name')
            || !$this->columnExists('publication_settings', 'setting_value')
        ) {
            return;
        }

        try {
            $cursor = Capsule::table('publications as p')
                ->join('publication_settings as ps', 'ps.publication_id', '=', 'p.publication_id')
                ->leftJoin('issues as i', Capsule::raw('CAST(i.issue_id AS CHAR(20))'), '=', 'ps.setting_value')
                ->where('ps.setting_name', 'issueId')
                ->whereNull('i.issue_id')
                ->select([
                    'p.publication_id as publication_id',
                    'p.submission_id as submission_id',
                    'ps.setting_value as setting_value',
                    'ps.locale as locale',
                ])
                ->cursor();
        } catch (\Throwable $e) {
            return;
        }

        foreach ($cursor as $row) {
            yield [
                'publication_id' => $row->publication_id,
                'submission_id' => $row->submission_id,
                'setting_value' => (string) ($row->setting_value ?? ''),
                'locale' => $row->locale ?? null,
            ];
        }
    }

    public function countInvalidPublicationIssueIdSettings(): int
    {
        if (!$this->tableExists('publication_settings')
            || !$this->tableExists('publications')
            || !$this->tableExists('issues')
        ) {
            return 0;
        }
        try {
            return (int) Capsule::table('publications as p')
                ->join('publication_settings as ps', 'ps.publication_id', '=', 'p.publication_id')
                ->leftJoin('issues as i', Capsule::raw('CAST(i.issue_id AS CHAR(20))'), '=', 'ps.setting_value')
                ->where('ps.setting_name', 'issueId')
                ->whereNull('i.issue_id')
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function deleteInvalidPublicationIssueIdSettings(): int
    {
        if (!$this->tableExists('publication_settings')
            || !$this->tableExists('publications')
            || !$this->tableExists('issues')
        ) {
            return 0;
        }
        $sql = 'DELETE ps FROM `publication_settings` AS ps'
            . ' INNER JOIN `publications` AS p ON ps.`publication_id` = p.`publication_id`'
            . ' LEFT JOIN `issues` AS i ON CAST(i.`issue_id` AS CHAR(20)) = ps.`setting_value`'
            . " WHERE ps.`setting_name` = 'issueId' AND i.`issue_id` IS NULL";
        try {
            return (int) Capsule::affectingStatement($sql);
        } catch (\Throwable $e) {
            return 0;
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
     * @param string $table
     * @return array{pk:?string,fk:?string}
     */
    public function getTableMetaPublic(string $table): array
    {
        return $this->getTableMeta($table);
    }

    public function getTablePrimaryKey(string $table): ?string
    {
        return $this->getTableMeta($table)['pk'];
    }

    public function getTableForeignKey(string $table): ?string
    {
        return $this->getTableMeta($table)['fk'];
    }

    /**
     * Yields identity values for rows belonging to dead journals on a nested cascade step.
     *
     * @param array<string, array{table:string, identity:string, source:string, column:string, parent:?string, assocType:?int, via:string, aggregate:bool}> $planByTable
     * @param array<int> $deadJournalIds
     * @return iterable<int|string>
     */
    public function findRowIdsByDeadJournalPath(array $step, array $planByTable, array $deadJournalIds): iterable
    {
        if (empty($deadJournalIds) || ($step['parent'] ?? null) === null || !empty($step['aggregate'])) {
            return;
        }

        $path = $this->buildDeadJournalCascadePath($step, $planByTable);
        if ($path === null) {
            return;
        }
        $leaf = $path[count($path) - 1];
        $root = $path[0];
        if (!$this->tableExists($root['table']) || !$this->tableExists($leaf['table'])
            || !$this->columnExists($leaf['table'], $leaf['identity'])) {
            return;
        }

        try {
            $leafIdx = count($path) - 1;
            $leafAlias = 't' . $leafIdx;
            $query = Capsule::table($root['table'] . ' as t0')
                ->select($leafAlias . '.' . $leaf['identity'] . ' as id');
            for ($i = 1; $i < count($path); $i++) {
                $parent = $path[$i - 1];
                $child = $path[$i];
                if (!$this->columnExists($parent['table'], $parent['identity'])
                    || !$this->columnExists($child['table'], $child['column'])) {
                    return;
                }
                $query->join(
                    $child['table'] . ' as t' . $i,
                    't' . ($i - 1) . '.' . $parent['identity'],
                    '=',
                    't' . $i . '.' . $child['column']
                );
                if ($child['assocType'] !== null) {
                    $query->where('t' . $i . '.assoc_type', $child['assocType']);
                }
            }
            $query->whereIn('t0.' . $root['column'], $deadJournalIds);
            if ($root['assocType'] !== null) {
                $query->where('t0.assoc_type', $root['assocType']);
            }
            if ($step['assocType'] !== null && $leafIdx > 0) {
                $query->where($leafAlias . '.assoc_type', $step['assocType']);
            }
            $cursor = $query->orderBy($leafAlias . '.' . $leaf['identity'])->cursor();
        } catch (\Throwable $e) {
            return;
        }

        foreach ($cursor as $row) {
            if ($row->id !== null) {
                yield $row->id;
            }
        }
    }

    /**
     * Quick existence check against the Illuminate schema builder. Public
     * because JournalCascadeRegistry verifies its map against the live schema.
     *
     * @param string $table
     * @return bool
     */
    public function tableExists(string $table): bool
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

    public function countReviewRevisionFiles(): int
    {
        if (!$this->tableExists('submission_files')) {
            return 0;
        }
        try {
            return (int) Capsule::table('submission_files')->where('file_stage', '=', 15)->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Deletes every submission_file stuck in REVIEW_REVISION status.
     */
    public function deleteAllReviewRevisionFiles(): int
    {
        if (!$this->tableExists('submission_files')) {
            return 0;
        }
        $deleted = 0;
        try {
            $ids = Capsule::table('submission_files')
                ->where('file_stage', '=', 15)
                ->pluck('submission_file_id');
        } catch (\Throwable $e) {
            return 0;
        }
        foreach ($ids as $id) {
            $deleted += $this->deleteReviewRevisionFile($id);
        }
        return $deleted;
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

    /**
     * Quick column existence check against the Illuminate schema builder.
     *
     * @param string $table
     * @param string $column
     * @return bool
     */
    public function columnExists(string $table, string $column): bool
    {
        try {
            return Capsule::schema()->hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Collects journal ids referenced by journal-scoped tables that have no
     * matching row in `journals` (Pass F). Reads the live journal id set once,
     * then diffs each root table's distinct references against it.
     *
     * @param array<string, string> $roots table => journal reference column
     * @return int[] Sorted, deduplicated dead journal ids
     */
    public function findDeadJournalIds(array $roots): array
    {
        if (!$this->tableExists('journals')) {
            return [];
        }
        $live = [];
        try {
            foreach (Capsule::table('journals')->select('journal_id')->cursor() as $row) {
                $live[(int) $row->journal_id] = true;
            }
        } catch (\Throwable $e) {
            return [];
        }

        $dead = [];
        foreach ($roots as $table => $column) {
            if (!$this->tableExists($table) || !$this->columnExists($table, $column)) {
                continue;
            }
            try {
                $cursor = Capsule::table($table)
                    ->select($column . ' as jid')
                    ->whereNotNull($column)
                    ->distinct()
                    ->cursor();
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($cursor as $row) {
                $id = (int) $row->jid;
                if ($id > 0 && !isset($live[$id])) {
                    $dead[$id] = true;
                }
            }
        }

        $out = array_keys($dead);
        sort($out);
        return $out;
    }

    /**
     * Returns the identity-column values of rows whose $column matches any of
     * $values. Used both to find rows belonging to a dead journal (matching
     * journal ids) and to walk one generation down a cascade chain (matching
     * parent ids). Values are chunked to keep WHERE IN clauses bounded.
     *
     * @param string $table Table to read
     * @param string $identity Column identifying a row
     * @param string $column Column to match against $values
     * @param array<int, int|string> $values Journal ids or parent ids
     * @param int|null $assocType When set, adds an assoc_type equality clause
     * @return array<int, int|string> Identity values, deduplicated
     */
    public function findRowIdsByColumn(string $table, string $identity, string $column, array $values, ?int $assocType = null): array
    {
        if (empty($values) || !$this->tableExists($table)) {
            return [];
        }
        if (!$this->columnExists($table, $identity) || !$this->columnExists($table, $column)) {
            return [];
        }

        $out = [];
        foreach (array_chunk(array_values($values), self::ID_CHUNK) as $chunk) {
            try {
                $query = Capsule::table($table)
                    ->select($identity . ' as id')
                    ->whereIn($column, $chunk);
                if ($assocType !== null) {
                    $query->where('assoc_type', $assocType);
                }
                $cursor = $query->cursor();
            } catch (\Throwable $e) {
                return $out;
            }
            foreach ($cursor as $row) {
                if ($row->id !== null) {
                    $out[(string) $row->id] = $row->id;
                }
            }
        }
        return array_values($out);
    }

    /**
     * Counts rows whose $column matches any of $values. Used by Pass F for
     * aggregate cascade roots (e.g. OJS 3.3 metrics) that have no row-level
     * surrogate primary key.
     *
     * @param string $table Table to read
     * @param string $column Column to match against $values
     * @param array<int, int|string> $values Journal ids or parent ids
     * @param int|null $assocType When set, adds an assoc_type equality clause
     * @return int Row count
     */
    public function countRowsByColumn(string $table, string $column, array $values, ?int $assocType = null): int
    {
        if (empty($values) || !$this->tableExists($table) || !$this->columnExists($table, $column)) {
            return 0;
        }

        $total = 0;
        foreach (array_chunk(array_values($values), self::ID_CHUNK) as $chunk) {
            try {
                $query = Capsule::table($table)->whereIn($column, $chunk);
                if ($assocType !== null) {
                    $query->where('assoc_type', $assocType);
                }
                $total += (int) $query->count();
            } catch (\Throwable $e) {
                return $total;
            }
        }
        return $total;
    }

    /**
     * Counts rows for a cascade step by joining back to the journal root.
     *
     * @param array<string, array{table:string, identity:string, source:string, column:string, parent:?string, assocType:?int, via:string, aggregate:bool}> $planByTable
     * @param array<int> $deadJournalIds
     */
    public function countRowsByDeadJournalPath(array $step, array $planByTable, array $deadJournalIds): int
    {
        if (empty($deadJournalIds) || ($step['parent'] ?? null) === null) {
            return 0;
        }

        $path = $this->buildDeadJournalCascadePath($step, $planByTable);
        if ($path === null) {
            return 0;
        }
        $root = $path[0];
        if (!$this->tableExists($root['table']) || !$this->tableExists($step['table'])) {
            return 0;
        }
        try {
            $query = Capsule::table($root['table'] . ' as t0');
            for ($i = 1; $i < count($path); $i++) {
                $parent = $path[$i - 1];
                $child = $path[$i];
                if (!$this->columnExists($parent['table'], $parent['identity'])
                    || !$this->columnExists($child['table'], $child['column'])) {
                    return 0;
                }
                $query->join(
                    $child['table'] . ' as t' . $i,
                    't' . ($i - 1) . '.' . $parent['identity'],
                    '=',
                    't' . $i . '.' . $child['column']
                );
                if ($child['assocType'] !== null) {
                    $query->where('t' . $i . '.assoc_type', $child['assocType']);
                }
            }

            $query->whereIn('t0.' . $root['column'], $deadJournalIds);
            if ($root['assocType'] !== null) {
                $query->where('t0.assoc_type', $root['assocType']);
            }

            return (int) $query->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @param array<string, array{table:string, identity:string, source:string, column:string, parent:?string, assocType:?int, via:string, aggregate:bool}> $planByTable
     * @return array<int, array{table:string, identity:string, source:string, column:string, parent:?string, assocType:?int, via:string, aggregate:bool}>|null
     */
    private function buildDeadJournalCascadePath(array $step, array $planByTable): ?array
    {
        if (($step['parent'] ?? null) === null) {
            return null;
        }
        $path = [];
        $current = $step;
        while (true) {
            array_unshift($path, $current);
            if ($current['parent'] === null) {
                break;
            }
            if (!isset($planByTable[$current['parent']])) {
                return null;
            }
            $current = $planByTable[$current['parent']];
        }
        return $path;
    }

    /**
     * Deletes rows for one cascade step scoped to a single dead journal.
     *
     * @param array<string, array{table:string, identity:string, source:string, column:string, parent:?string, assocType:?int, via:string, aggregate:bool}> $planByTable
     */
    public function deleteRowsByDeadJournalPath(array $step, array $planByTable, int $journalId): int
    {
        if ($step['source'] === 'journal') {
            return $this->deleteRowsByColumn(
                $step['table'],
                $step['column'],
                [$journalId],
                $step['assocType']
            );
        }

        $path = $this->buildDeadJournalCascadePath($step, $planByTable);
        if ($path === null || !$this->tableExists($step['table'])) {
            return 0;
        }

        $leafIdx = count($path) - 1;
        $leafAlias = 't' . $leafIdx;
        $root = $path[0];

        try {
            $sql = 'DELETE ' . $leafAlias . ' FROM `' . $path[$leafIdx]['table'] . '` AS ' . $leafAlias;
            for ($i = $leafIdx; $i >= 1; $i--) {
                $parent = $path[$i - 1];
                $child = $path[$i];
                if (!$this->columnExists($parent['table'], $parent['identity'])
                    || !$this->columnExists($child['table'], $child['column'])) {
                    return 0;
                }
                $sql .= ' INNER JOIN `' . $parent['table'] . '` AS t' . ($i - 1)
                    . ' ON t' . ($i - 1) . '.`' . $parent['identity'] . '` = t' . $i . '.`' . $child['column'] . '`';
            }
            $sql .= ' WHERE t0.`' . $root['column'] . '` = ' . (int) $journalId;
            if ($root['assocType'] !== null) {
                $sql .= ' AND t0.`assoc_type` = ' . (int) $root['assocType'];
            }
            if ($step['assocType'] !== null && $leafIdx > 0) {
                $sql .= ' AND ' . $leafAlias . '.`assoc_type` = ' . (int) $step['assocType'];
            }
            return (int) Capsule::affectingStatement($sql);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function deleteSubmissionFileDependentsForJournal(int $journalId): int
    {
        if (!$this->tableExists('submission_files') || !$this->tableExists('submissions')) {
            return 0;
        }
        try {
            $fileIds = Capsule::table('submission_files as sf')
                ->join('submissions as s', 's.submission_id', '=', 'sf.submission_id')
                ->where('s.context_id', '=', $journalId)
                ->pluck('sf.submission_file_id');
        } catch (\Throwable $e) {
            return 0;
        }
        if ($fileIds->isEmpty()) {
            return 0;
        }
        $deleted = 0;
        foreach (['review_round_files', 'review_files', 'publication_galleys'] as $table) {
            if ($this->tableExists($table) && $this->columnExists($table, 'submission_file_id')) {
                foreach (array_chunk($fileIds->all(), self::ID_CHUNK) as $chunk) {
                    $deleted += (int) Capsule::table($table)->whereIn('submission_file_id', $chunk)->delete();
                }
            }
        }
        return $deleted;
    }

    public function registerJournalCascadeScope(array &$scopeByTable, string $table, array $step, int $journalId): void
    {
        $this->registerJournalCascadeScopeForDeadJournals($scopeByTable, $table, $step, [$journalId]);
    }

    /**
     * @param array<int> $deadJournalIds
     * @param array<string, callable(): \Illuminate\Database\Query\Builder> $scopeByTable
     */
    public function registerJournalCascadeScopeForDeadJournals(
        array &$scopeByTable,
        string $table,
        array $step,
        array $deadJournalIds
    ): void {
        if (empty($deadJournalIds)) {
            return;
        }
        $identity = $step['identity'];
        $column = $step['column'];
        $assocType = $step['assocType'];
        $scopeByTable[$table] = function () use ($table, $identity, $column, $deadJournalIds, $assocType) {
            $q = Capsule::table($table)->select($identity)->whereIn($column, $deadJournalIds);
            if ($assocType !== null) {
                $q->where('assoc_type', $assocType);
            }
            return $q;
        };
    }

    /**
     * @param array<string, callable(): \Illuminate\Database\Query\Builder> $scopeByTable
     */
    public function countRowsByCascadeScope(string $table, array $step, array &$scopeByTable): int
    {
        $parent = $step['parent'];
        if ($parent === null || !isset($scopeByTable[$parent]) || !$this->tableExists($table)) {
            return 0;
        }
        try {
            $query = Capsule::table($table)->whereIn($step['column'], $scopeByTable[$parent]());
            if ($step['assocType'] !== null) {
                $query->where('assoc_type', $step['assocType']);
            }
            $count = (int) $query->count();
        } catch (\Throwable $e) {
            return 0;
        }
        if ($count > 0 && $this->columnExists($table, $step['identity'])) {
            $identity = $step['identity'];
            $column = $step['column'];
            $assocType = $step['assocType'];
            $scopeByTable[$table] = function () use ($table, $identity, $column, &$scopeByTable, $parent, $assocType) {
                $q = Capsule::table($table)->select($identity)->whereIn($column, $scopeByTable[$parent]());
                if ($assocType !== null) {
                    $q->where('assoc_type', $assocType);
                }
                return $q;
            };
        }
        return $count;
    }

    /**
     * Counts rows for one journal-cascade step using nested subqueries so
     * Pass F never loads millions of parent ids into PHP.
     *
     * @param array<string, callable(): \Illuminate\Database\Query\Builder> $scopeByTable
     * @deprecated Use registerJournalCascadeScope + countRowsByCascadeScope from Scanner
     */
    public function countRowsForJournalCascadeStep(array $step, int $journalId, array &$scopeByTable): int
    {
        $table = $step['table'];
        if (!$this->tableExists($table) || !$this->columnExists($table, $step['column'])) {
            return 0;
        }

        if ($step['source'] === 'journal') {
            if (!empty($step['aggregate'])) {
                return $this->countRowsByColumn(
                    $table,
                    $step['column'],
                    [$journalId],
                    $step['assocType']
                );
            }
            if (!$this->columnExists($table, $step['identity'])) {
                return 0;
            }
            try {
                $query = Capsule::table($table)->where($step['column'], $journalId);
                if ($step['assocType'] !== null) {
                    $query->where('assoc_type', $step['assocType']);
                }
                $count = (int) $query->count();
            } catch (\Throwable $e) {
                return 0;
            }
            if ($count === 0) {
                return 0;
            }
            $identity = $step['identity'];
            $column = $step['column'];
            $assocType = $step['assocType'];
            $scopeByTable[$table] = function () use ($table, $identity, $column, $journalId, $assocType) {
                $q = Capsule::table($table)->select($identity)->where($column, $journalId);
                if ($assocType !== null) {
                    $q->where('assoc_type', $assocType);
                }
                return $q;
            };
            return $count;
        }

        $parent = $step['parent'];
        if ($parent === null || !isset($scopeByTable[$parent]) || !$this->columnExists($table, $step['identity'])) {
            return 0;
        }

        try {
            $parentSub = $scopeByTable[$parent]();
            $query = Capsule::table($table)->whereIn($step['column'], $parentSub);
            if ($step['assocType'] !== null) {
                $query->where('assoc_type', $step['assocType']);
            }
            $count = (int) $query->count();
        } catch (\Throwable $e) {
            return 0;
        }
        if ($count === 0) {
            return 0;
        }

        $identity = $step['identity'];
        $column = $step['column'];
        $assocType = $step['assocType'];
        $scopeByTable[$table] = function () use ($table, $identity, $column, &$scopeByTable, $parent, $assocType) {
            $q = Capsule::table($table)->select($identity)->whereIn($column, $scopeByTable[$parent]());
            if ($assocType !== null) {
                $q->where('assoc_type', $assocType);
            }
            return $q;
        };
        return $count;
    }

    /**
     * Deletes rows whose $column matches any of $values, in chunks.
     * WRITES to the database.
     *
     * @param string $table Table to delete from
     * @param string $column Column to match against $values
     * @param array<int, int|string> $values Identity or FK values to remove
     * @param int|null $assocType When set, adds an assoc_type equality clause
     * @return int Number of rows deleted
     */
    public function deleteRowsByColumn(string $table, string $column, array $values, ?int $assocType = null): int
    {
        if (empty($values) || !$this->tableExists($table) || !$this->columnExists($table, $column)) {
            return 0;
        }
        $deleted = 0;
        foreach (array_chunk(array_values($values), self::ID_CHUNK) as $chunk) {
            $query = Capsule::table($table)->whereIn($column, $chunk);
            if ($assocType !== null) {
                $query->where('assoc_type', $assocType);
            }
            $deleted += (int) $query->delete();
        }
        return $deleted;
    }

    /**
     * Deletes rows that FK to submission_files.submission_file_id. Required when
     * the DB has FK constraints and those tables are not removed via review_round_id.
     *
     * @param array<int, int|string> $submissionIds
     */
    public function deleteSubmissionFileDependents(array $submissionIds): int
    {
        if (empty($submissionIds) || !$this->tableExists('submission_files')) {
            return 0;
        }

        $fileIds = [];
        foreach (array_chunk(array_values($submissionIds), self::ID_CHUNK) as $chunk) {
            try {
                foreach (Capsule::table('submission_files')
                    ->whereIn('submission_id', $chunk)
                    ->pluck('submission_file_id') as $id) {
                    if ($id !== null) {
                        $fileIds[(string) $id] = $id;
                    }
                }
            } catch (\Throwable $e) {
                return 0;
            }
        }
        if (empty($fileIds)) {
            return 0;
        }

        $deleted = 0;
        foreach (['review_round_files', 'review_files', 'publication_galleys'] as $table) {
            if ($this->tableExists($table) && $this->columnExists($table, 'submission_file_id')) {
                $deleted += $this->deleteRowsByColumn($table, 'submission_file_id', array_values($fileIds));
            }
        }
        return $deleted;
    }

    /**
     * Counts rows in `files` that are not referenced by submission_files or
     * submission_file_revisions (Pass C — unreferenced blob orphans).
     */
    public function countUnreferencedFiles(): int
    {
        if (!$this->tableExists('files') || !$this->tableExists('submission_files')) {
            return 0;
        }
        try {
            $query = Capsule::table('files as f');
            $this->applyUnreferencedFilesScope($query);
            return (int) $query->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Yields file_id for every unreferenced blob row in `files`.
     *
     * @return \Generator<int, int|string>
     */
    public function findUnreferencedFileIds(): \Generator
    {
        if (!$this->tableExists('files') || !$this->tableExists('submission_files')) {
            return;
        }
        try {
            $query = Capsule::table('files as f')
                ->select('f.file_id')
                ->orderBy('f.file_id');
            $this->applyUnreferencedFilesScope($query);
            $cursor = $query->cursor();
        } catch (\Throwable $e) {
            return;
        }
        foreach ($cursor as $row) {
            yield $row->file_id;
        }
    }

    /**
     * Deletes one unreferenced blob via the OJS file service when possible,
     * falling back to removing the DB row only.
     *
     * @param int|string $fileId
     */
    public function deleteUnreferencedFile($fileId): int
    {
        if (!$this->tableExists('files') || $this->isFileReferenced($fileId)) {
            return 0;
        }
        try {
            \Services::get('file')->delete($fileId);
            return 1;
        } catch (\Throwable $e) {
            return (int) Capsule::table('files')->where('file_id', $fileId)->delete();
        }
    }

    /**
     * Deletes every unreferenced blob in `files`, re-checking references
     * before each delete so live-journal rows are never removed.
     */
    public function deleteUnreferencedFiles(): int
    {
        $deleted = 0;
        foreach ($this->findUnreferencedFileIds() as $fileId) {
            $deleted += $this->deleteUnreferencedFile($fileId);
        }
        return $deleted;
    }

    /**
     * True when any submission_files or submission_file_revisions row still
     * points at this file_id.
     *
     * @param int|string $fileId
     */
    public function isFileReferenced($fileId): bool
    {
        foreach (self::FILES_REFERENCER_TABLES as [$table, $column]) {
            if (!$this->tableExists($table)) {
                continue;
            }
            if (Capsule::table($table)->where($column, $fileId)->exists()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Restricts a query on alias `f` (files) to rows with no referencers.
     *
     * @param \Illuminate\Database\Query\Builder $query
     */
    private function applyUnreferencedFilesScope($query): void
    {
        foreach (self::FILES_REFERENCER_TABLES as [$table, $column]) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $query->whereNotExists(function ($q) use ($table, $column) {
                $q->select(Capsule::raw(1))
                    ->from("{$table} as ref")
                    ->whereColumn("ref.{$column}", 'f.file_id');
            });
        }
    }

    /**
     * Runs $work inside a database transaction, committing on return and
     * rolling back on any throwable. The throwable is re-thrown so the caller
     * can record it per unit of work.
     *
     * @param callable():int $work
     * @return int Whatever $work returned
     */
    public function runInTransaction(callable $work): int
    {
        $connection = Capsule::connection();
        $connection->beginTransaction();
        try {
            $result = $work();
            $connection->commit();
            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }
}
