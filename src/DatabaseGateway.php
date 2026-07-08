<?php

/**
 * @file tools/SettingsHealthCheck/DatabaseGateway.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @interface DatabaseGateway
 *
 * @brief Abstraction over the database read operations the Scanner needs.
 *        Real impl wraps Illuminate DB facade; tests use a fake.
 */

namespace APP\tools\settingsHealthCheck\src;

interface DatabaseGateway
{
    public function getDatabaseName(): string;

    public function getSitePrimaryLocale(): string;

    /**
     * @return string[] All tables in the current schema with a `locale` column whose name ends in `_settings`.
     */
    public function discoverSettingsTables(): array;

    /**
     * Pass A: rows where setting_name is one of the given multilingual names AND locale is empty/null.
     *
     * @param string[] $settingNames
     * @return iterable<array{pk:int|string, fk:int|string|null, setting_name:string, locale:?string, setting_value:?string}>
     */
    public function getMultilingualOffenders(string $table, array $settingNames): iterable;

    /**
     * Pass B step 1: setting_names in $table that have BOTH empty-locale and non-empty-locale rows.
     *
     * @return string[]
     */
    public function findSuspectSettingNames(string $table): array;

    /**
     * Pass B step 2: empty-locale rows for the given suspect setting names.
     *
     * @param string[] $settingNames
     * @return iterable<array{pk:int|string, fk:int|string|null, setting_name:string, locale:?string, setting_value:?string}>
     */
    public function getEmptyLocaleRowsForSettings(string $table, array $settingNames): iterable;

    /**
     * Pass C: resolve the FK relationship for a settings table.
     *
     * @return array{column:string, parentTable:string, parentColumn:string}|null null when no
     *         FK can be resolved (no constraint defined and no convention match).
     */
    public function getForeignKey(string $settingsTable): ?array;

    /**
     * Pass C: rows in $settingsTable whose FK value has no matching row in $parentTable.
     *
     * @return iterable<array{pk:int|string, fk:int|string|null, setting_name:string, locale:?string, setting_value:?string}>
     */
    public function findOrphans(string $settingsTable, string $fkCol, string $parentTable, string $parentCol): iterable;

    /**
     * Pass D step 1: which of $candidateColumns exist in $table AND allow NULL.
     *
     * @param string[] $candidateColumns
     * @return string[]
     */
    public function filterNullableColumns(string $table, array $candidateColumns): array;

    /**
     * Pass D1: rows in $table where $column IS NULL.
     *
     * @return iterable<array{pk:int|string, column:string}>
     */
    public function findRowsWithNullColumn(string $table, string $pk, string $column): iterable;

    /**
     * Pass D2: rows in $settingsTable where setting_value IS NULL.
     *
     * @return iterable<array{pk:int|string, fk:int|string|null, setting_name:string, locale:?string, setting_value:?string}>
     */
    public function findRowsWithNullSettingValue(string $settingsTable): iterable;

    /**
     * Fix (orphans): delete the offending row. On surrogate-key tables the row
     * is matched by primary key; on OJS 3.3 composite-key tables it is matched
     * by (entity id, setting_name, locale) since the PK alone is not unique.
     *
     * @param int|string $pk row anchor value as reported in the Finding
     * @return int number of rows deleted
     */
    public function deleteSettingRow(string $table, $pk, string $settingName, ?string $locale): int;

    /**
     * Fix (missing locale): stamp $newLocale onto the offending empty/null-locale
     * row. Same row-matching rules as deleteSettingRow().
     *
     * @param int|string $pk row anchor value as reported in the Finding
     * @return int number of rows updated
     */
    public function setSettingRowLocale(string $table, $pk, string $settingName, ?string $oldLocale, string $newLocale): int;

    /**
     * Pass E: Find submission files under REVIEW_REVISION status.
     *
     * @return iterable<array{pk:int|string, fk:int|string|null, setting_name:string, locale:?string, setting_value:?string}>
     */
    public function findReviewRevisionFiles(): iterable;

    /**
     * Fix (review revision files): Delete the submission file, its revisions, settings,
     * review associations, and the underlying physical file (if not shared).
     *
     * @param int|string $submissionFileId
     * @return int number of submission_files records deleted
     */
    public function deleteReviewRevisionFile($submissionFileId): int;
}
