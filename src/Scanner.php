<?php

/**
 * @file tools/SettingsHealthCheck/Scanner.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Scanner
 *
 * @brief Runs the detection passes (A–E) over a database via the gateway.
 *        Eager: scan() executes synchronously and returns Finding[].
 *        tableResults and contextStats are guaranteed populated after scan()
 *        returns regardless of how the caller iterates the results.
 */

namespace APP\tools\settingsHealthCheck\src;

final class Scanner
{
    /** Pass A+B */ public const CHECK_LOCALE = 'locale';
    /** Pass C */ public const CHECK_ORPHAN = 'orphan';
    /** Pass D */ public const CHECK_EMPTY = 'empty';
    /** Pass E */ public const CHECK_REVIEW = 'review';
    /** Pass F */ public const CHECK_JOURNAL = 'journal';
    private array $contextStats = [
        'database' => '',
        'tablesScanned' => 0,
        'schemaMapped' => 0,
        'autoDiscovered' => 0,
    ];

    /** @var string[] */
    private array $warnings = [];

    /** @var array<string, array{kind:string, settingsChecked:string[], findingsCount:int, status:string, note:string}> */
    private array $tableResults = [];

    /** @var array<string, array<string, true>> */
    private array $schemaMap = [];

    /** @var array<string, array{table:string,pk:string,requiredColumns:string[]}> */
    private array $entityMap = [];

    /** @var array<string, array{table:string,pk:string,nullableRequired:string[],findingsCount:int,status:string,note:string}> */
    private array $entityResults = [];

    /** @var JournalCascadeRegistry|null */
    private $cascadeRegistry = null;

    /** @var array<int, array{journalId:int, tables:array<string,int>, rows:int}> */
    private array $deadJournalResults = [];

    /** @var string[] */
    private array $unmappedTables = [];

    private string $primaryLocale = 'en';

    private bool $initialized = false;

    /** @var Finding[] */
    private array $findings = [];

    /** @var ProgressReporter|null */
    private $progress = null;

    private const SCENARIO_LOCALE = 'Bad locale tags';
    private const SCENARIO_ORPHAN = 'Orphaned settings, entities & files';
    private const SCENARIO_ENTITY_REF = 'Invalid entity references';
    private const SCENARIO_REQUIRED_NULL = 'Required fields NULL';
    private const SCENARIO_SETTING_NULL = 'NULL setting_value';
    private const SCENARIO_REVIEW = 'REVIEW_REVISION files';
    private const SCENARIO_JOURNAL = 'Deleted journal leftovers';

    /** @var IlluminateDatabaseGateway */
    private $gateway;

    /**
     * @brief Wires the database gateway used by all detection passes, and the
     *        cascade registry used by Pass F. The registry is optional so the
     *        other passes can run without building it.
     */
    public function __construct(IlluminateDatabaseGateway $gateway, ?JournalCascadeRegistry $cascadeRegistry = null)
    {
        $this->gateway = $gateway;
        $this->cascadeRegistry = $cascadeRegistry;
    }

    /**
     * Resolves database name, primary locale, and auto-discovered tables.
     * Pre-populates per-table results with status='pending' so every known
     * table has a result slot before any pass runs. Must be called once
     * before scan().
     *
     * @param array<string, array<string, true>> $schemaMap table => set of multilingual setting_name
     * @param array<string, array{table:string,pk:string,requiredColumns:string[]}> $entityMap mainTable => meta
     */
    public function initialize(array $schemaMap, array $entityMap = []): void
    {
        $this->schemaMap = $schemaMap;
        $this->entityMap = $entityMap;
        $this->contextStats['database'] = $this->gateway->getDatabaseName();
        $this->primaryLocale = $this->gateway->getSitePrimaryLocale();
        $this->contextStats['schemaMapped'] = count($schemaMap);

        $discovered = $this->gateway->discoverSettingsTables();
        $this->unmappedTables = array_values(array_diff($discovered, array_keys($schemaMap)));
        $this->contextStats['autoDiscovered'] = count($this->unmappedTables);
        $this->contextStats['tablesScanned'] = count($schemaMap) + count($this->unmappedTables);

        foreach ($schemaMap as $table => $settingNamesSet) {
            $this->tableResults[$table] = [
                'kind' => 'schema',
                'settingsChecked' => array_keys($settingNamesSet),
                'findingsCount' => 0,
                'status' => 'pending',
                'note' => '',
                'orphanCount' => 0,
                'orphanFk' => null,
                'orphanStatus' => 'pending',
            ];
        }
        foreach ($this->unmappedTables as $table) {
            $this->tableResults[$table] = [
                'kind' => 'heuristic',
                'settingsChecked' => [],
                'findingsCount' => 0,
                'status' => 'pending',
                'note' => '',
                'orphanCount' => 0,
                'orphanFk' => null,
                'orphanStatus' => 'pending',
            ];
        }

        // Orphan-only *_settings tables (no locale column, or not auto-discovered).
        foreach ($this->gateway->discoverAllSettingsTables() as $table) {
            if (isset($this->tableResults[$table])) {
                continue;
            }
            $this->tableResults[$table] = [
                'kind' => 'orphan_only',
                'settingsChecked' => [],
                'findingsCount' => 0,
                'status' => 'pending',
                'note' => '',
                'orphanCount' => 0,
                'orphanFk' => null,
                'orphanStatus' => 'pending',
            ];
        }
        $this->contextStats['tablesScanned'] = count($this->tableResults);

        $this->initialized = true;
    }

    /**
     * Synchronously runs the requested check passes and returns every
     * Finding collected. After return, getTableResults(), getContextStats(),
     * and getEntityResults() are fully populated.
     *
     * @param string[]|null $checks Subset of CHECK_* constants. Null runs every check.
     * @return Finding[]
     */
    public function scan(?array $checks = null): array
    {
        if (!$this->initialized) {
            throw new \LogicException('Scanner::initialize() must be called before scan().');
        }

        $checks = $checks ?? [
            self::CHECK_LOCALE,
            self::CHECK_ORPHAN,
            self::CHECK_EMPTY,
            self::CHECK_REVIEW,
            self::CHECK_JOURNAL,
        ];
        $run = array_fill_keys($checks, true);

        $this->findings = [];
        $this->progress = new ProgressReporter($this->countScanSteps($run));
        $this->progress->message('Scanning database...');

        // Pass A — schema-driven on mapped tables.
        if (!empty($run[self::CHECK_LOCALE])) {
        foreach ($this->schemaMap as $table => $settingNamesSet) {
            $this->reportStep($table, self::SCENARIO_LOCALE);
            $names = array_keys($settingNamesSet);
            $count = 0;
            $status = 'clean';
            $note = '';
            try {
                $count = $this->gateway->countEmptyLocaleRows($table, $names);
                if ($count > 0) {
                    $this->findings[] = new Finding(
                        $table,
                        Finding::bulkPk('locale-schema'),
                        implode('|', $names),
                        '',
                        null,
                        null,
                        Finding::REASON_SCHEMA_MISSING_LOCALE,
                        $this->primaryLocale,
                        $count
                    );
                }
            } catch (\Throwable $e) {
                $status = 'error';
                $note = $e->getMessage();
                $this->warnings[] = sprintf('Pass A failed for %s: %s', $table, $note);
            }
            if ($status !== 'error' && $count > 0) {
                $status = 'findings';
            }
            $prev = $this->tableResults[$table];
            $this->tableResults[$table] = [
                'kind' => 'schema',
                'settingsChecked' => $names,
                'findingsCount' => $count,
                'status' => $status,
                'note' => $note,
                'orphanCount' => $prev['orphanCount'],
                'orphanFk' => $prev['orphanFk'],
                'orphanStatus' => $prev['orphanStatus'],
            ];
        }

        // Pass B — heuristic on auto-discovered tables not already covered by Pass A.
        foreach ($this->unmappedTables as $table) {
            $this->reportStep($table, self::SCENARIO_LOCALE);
            $count = 0;
            $status = 'clean';
            $note = '';
            $suspects = [];
            try {
                $suspects = $this->gateway->findSuspectSettingNames($table);
                if (!empty($suspects)) {
                    $count = $this->gateway->countEmptyLocaleRows($table, $suspects);
                    if ($count > 0) {
                        $this->findings[] = new Finding(
                            $table,
                            Finding::bulkPk('locale-heuristic'),
                            implode('|', $suspects),
                            '',
                            null,
                            null,
                            Finding::REASON_HEURISTIC_LOCALE_MISMATCH,
                            '',
                            $count
                        );
                    }
                } else {
                    $note = 'no setting names with mixed-locale rows';
                }
            } catch (\Throwable $e) {
                $status = 'error';
                $note = $e->getMessage();
                $this->warnings[] = sprintf('Pass B failed for %s: %s', $table, $note);
            }
            if ($status !== 'error' && $count > 0) {
                $status = 'findings';
            }
            $prev = $this->tableResults[$table];
            $this->tableResults[$table] = [
                'kind' => 'heuristic',
                'settingsChecked' => $suspects,
                'findingsCount' => $count,
                'status' => $status,
                'note' => $note,
                'orphanCount' => $prev['orphanCount'],
                'orphanFk' => $prev['orphanFk'],
                'orphanStatus' => $prev['orphanStatus'],
            ];
        }

        } // end CHECK_LOCALE

        if (!empty($run[self::CHECK_ORPHAN])) {
            foreach ($this->tableResults as $table => $_r) {
                $this->reportStep($table, self::SCENARIO_ORPHAN);
                $this->runOrphanPass($table);
            }
            $this->reportStep('files', self::SCENARIO_ORPHAN);
            $this->runFilesOrphanPass();
            $this->runEntityOrphanPass();
        }

        if (!empty($run[self::CHECK_EMPTY])) {
            foreach ($this->entityMap as $mainTable => $entity) {
                $this->reportStep($mainTable, self::SCENARIO_REQUIRED_NULL);
                $this->runRequiredNullPass($mainTable, $entity);
            }

            foreach ($this->tableResults as $table => $_r) {
                if (!$this->gateway->columnExists($table, 'setting_name')) {
                    continue;
                }
                $this->reportStep($table, self::SCENARIO_SETTING_NULL);
                $this->runSettingValueNullPass($table);
            }
        }

        if (!empty($run[self::CHECK_REVIEW])) {
            $this->reportStep('submission_files', self::SCENARIO_REVIEW);
            $this->runReviewPass();
        }

        if (!empty($run[self::CHECK_JOURNAL])) {
            $this->runDeletedJournalPass();
        }

        $this->finalizeTableResults($run);

        if ($this->progress !== null) {
            $this->progress->finish('Scan complete.');
            $this->progress = null;
        }

        return $this->findings;
    }

    /** @param array<string, true> $run */
    private function countScanSteps(array $run): int
    {
        $total = 0;
        if (!empty($run[self::CHECK_LOCALE])) {
            $total += count($this->schemaMap) + count($this->unmappedTables);
        }
        if (!empty($run[self::CHECK_ORPHAN])) {
            $total += count($this->tableResults) + 1 + count(EntityReferenceRegistry::rules());
        }
        if (!empty($run[self::CHECK_EMPTY])) {
            $total += count($this->entityMap);
            foreach ($this->tableResults as $table => $_r) {
                if ($this->gateway->columnExists($table, 'setting_name')) {
                    $total++;
                }
            }
        }
        if (!empty($run[self::CHECK_REVIEW])) {
            $total++;
        }
        if (!empty($run[self::CHECK_JOURNAL]) && $this->cascadeRegistry !== null) {
            try {
                $total += count($this->cascadeRegistry->build());
            } catch (\Throwable $e) {
                $total++;
            }
        }
        return max(1, $total);
    }

    private function reportStep(string $table, string $scenario): void
    {
        if ($this->progress !== null) {
            $this->progress->step($table, $scenario);
        }
    }

    /**
     * Resolves leftover per-table state after a partial scan so the report
     * never claims a dimension was checked when its pass was skipped:
     *  - skipped locale pass: clear settingsChecked, note that it was not run;
     *  - skipped orphan pass: mark the orphan sub-status 'skipped';
     *  - any still-'pending' status collapses to 'clean'.
     *
     * @param array<string, true> $run Map of check constants that actually ran
     */
    private function finalizeTableResults(array $run): void
    {
        $localeRan = !empty($run[self::CHECK_LOCALE]);
        $orphanRan = !empty($run[self::CHECK_ORPHAN]);

        foreach ($this->tableResults as $table => $r) {
            if ($table === 'submission_files_review' || $table === 'files' || strpos($table, 'deleted_journal:') === 0) {
                continue;
            }
            if (!$localeRan) {
                $r['settingsChecked'] = [];
                if ($r['note'] === '') {
                    $r['note'] = '(locale check not run)';
                }
            }
            if (!$orphanRan && ($r['orphanStatus'] ?? 'pending') === 'pending') {
                $r['orphanStatus'] = 'skipped';
            }
            if ($r['status'] === 'pending') {
                $r['status'] = 'clean';
            }
            $this->tableResults[$table] = $r;
        }
    }

    /**
     * Pass D1 — scans main entity tables for schema-required columns that
     * are declared nullable in the database and contain NULL rows. Flags
     * each such row as a REQUIRED_NULL finding.
     *
     * @param string $mainTable The entity's canonical table (e.g. "journals")
     * @param array{table:string,pk:string,requiredColumns:string[]} $entity Entity metadata from the schema registry
     */
    private function runRequiredNullPass(string $mainTable, array $entity): void
    {
        $pk = $entity['pk'];
        $required = $entity['requiredColumns'];
        $status = 'clean';
        $note = '';
        $count = 0;
        $nullableRequired = [];
        try {
            $nullableRequired = $this->gateway->filterNullableColumns($mainTable, $required);
            foreach ($nullableRequired as $column) {
                $columnCount = $this->gateway->countRowsWithNullColumn($mainTable, $column);
                if ($columnCount > 0) {
                    $count += $columnCount;
                    $this->findings[] = new Finding(
                        $mainTable,
                        Finding::bulkPk('required-null', $column),
                        null,
                        $column,
                        null,
                        null,
                        Finding::REASON_REQUIRED_NULL,
                        '',
                        $columnCount
                    );
                }
            }
            if (empty($nullableRequired)) {
                $note = 'no nullable required columns (DB enforces NOT NULL)';
            }
        } catch (\Throwable $e) {
            $status = 'error';
            $note = $e->getMessage();
            $this->warnings[] = sprintf('Pass D1 failed for %s: %s', $mainTable, $note);
        }
        if ($status !== 'error' && $count > 0) {
            $status = 'findings';
        }
        $this->entityResults[$mainTable] = [
            'table' => $mainTable,
            'pk' => $pk,
            'nullableRequired' => $nullableRequired,
            'findingsCount' => $count,
            'status' => $status,
            'note' => $note,
        ];
    }

    /**
     * Pass D2 — scans every known *_settings table for rows where
     * setting_value IS NULL. Flags each as a SETTING_VALUE_NULL finding.
     *
     * @param string $table A *_settings table name
     */
    private function runSettingValueNullPass(string $table): void
    {
        $r = $this->tableResults[$table];
        $count = 0;
        try {
            $count = $this->gateway->countRowsWithNullSettingValue($table);
            if ($count > 0) {
                $this->findings[] = new Finding(
                    $table,
                    Finding::bulkPk('setting-null'),
                    null,
                    'setting_value',
                    null,
                    null,
                    Finding::REASON_SETTING_VALUE_NULL,
                    '',
                    $count
                );
            }
        } catch (\Throwable $e) {
            $this->warnings[] = sprintf('Pass D2 failed for %s: %s', $table, $e->getMessage());
            $r['note'] = trim(($r['note'] === '' ? '' : $r['note'] . '; ') . 'null-value check: ' . $e->getMessage());
            $r['status'] = 'error';
            $this->tableResults[$table] = $r;
            return;
        }
        if ($count > 0) {
            $r['findingsCount'] += $count;
            $r['nullValueCount'] = ($r['nullValueCount'] ?? 0) + $count;
            if ($r['status'] === 'pending' || $r['status'] === 'clean') {
                $r['status'] = 'findings';
            }
        } else {
            $r['nullValueCount'] = $r['nullValueCount'] ?? 0;
        }
        $this->tableResults[$table] = $r;
    }

    /**
     * Pass C — resolves the FK for a single settings table, then joins
     * against the parent table to find rows whose parent entity no longer
     * exists. Flags each as an ORPHAN_ENTITY finding.
     *
     * @param string $table A *_settings table name
     */
    private function runOrphanPass(string $table): void
    {
        $r = $this->tableResults[$table];
        $orphanStatus = 'clean';
        $orphanCount = 0;
        $orphanFk = null;
        try {
            $foreignKeys = $this->gateway->getForeignKeys($table);
            if (empty($foreignKeys)) {
                $orphanStatus = 'skipped';
                $r['orphanFk'] = null;
            } else {
                $fkLabels = [];
                foreach ($foreignKeys as $fk) {
                    $fkLabels[] = sprintf('%s -> %s(%s)', $fk['column'], $fk['parentTable'], $fk['parentColumn']);
                    $ignoreZero = !empty($fk['ignoreZero']);
                    $fkCount = $this->gateway->countOrphans(
                        $table,
                        $fk['column'],
                        $fk['parentTable'],
                        $fk['parentColumn'],
                        $ignoreZero
                    );
                    if ($fkCount > 0) {
                        $orphanCount += $fkCount;
                        $this->findings[] = new Finding(
                            $table,
                            Finding::bulkPk('orphan', sprintf(
                                '%s:%s:%s:%s',
                                $fk['column'],
                                $fk['parentTable'],
                                $fk['parentColumn'],
                                $ignoreZero ? '1' : '0'
                            )),
                            null,
                            $fk['column'],
                            null,
                            null,
                            Finding::REASON_ORPHAN_ENTITY,
                            '',
                            $fkCount
                        );
                    }
                }
                $orphanFk = implode('; ', $fkLabels);
            }
            if ($table === 'publication_settings') {
                $issueCount = $this->gateway->countInvalidPublicationIssueIdSettings();
                if ($issueCount > 0) {
                    $orphanCount += $issueCount;
                    $this->findings[] = new Finding(
                        $table,
                        Finding::bulkPk('issueId'),
                        null,
                        'issueId',
                        null,
                        null,
                        Finding::REASON_ORPHAN_ENTITY,
                        '',
                        $issueCount
                    );
                }
                if ($orphanCount > 0 && $orphanFk === null) {
                    $orphanFk = 'setting_value -> issues(issue_id) (issueId)';
                }
                if ($orphanCount > 0 && $orphanStatus === 'skipped') {
                    $orphanStatus = 'clean';
                }
            }
        } catch (\Throwable $e) {
            $orphanStatus = 'error';
            $r['note'] = trim(($r['note'] === '' ? '' : $r['note'] . '; ') . 'orphan check: ' . $e->getMessage());
            $this->warnings[] = sprintf('Pass C failed for %s: %s', $table, $e->getMessage());
        }
        if ($orphanStatus === 'clean' && $orphanCount > 0) {
            $orphanStatus = 'findings';
        }
        $r['orphanCount'] = $orphanCount;
        $r['orphanFk'] = $orphanFk;
        $r['orphanStatus'] = $orphanStatus;
        if ($r['status'] === 'pending' || $r['status'] === 'clean') {
            if ($orphanStatus === 'error' && $r['status'] !== 'error') {
                $r['status'] = 'error';
            } elseif ($orphanStatus === 'findings') {
                $r['status'] = 'findings';
            } elseif ($r['status'] === 'pending') {
                $r['status'] = 'clean';
            }
        }
        $r['findingsCount'] += $orphanCount;
        $this->tableResults[$table] = $r;
    }

    /**
     * Pass C (files) — flags rows in the central `files` blob table that are
     * no longer referenced by submission_files or submission_file_revisions.
     * Emits one aggregate finding for the interactive report.
     */
    private function runFilesOrphanPass(): void
    {
        $orphanStatus = 'clean';
        $orphanCount = 0;
        $note = '';
        try {
            $orphanCount = $this->gateway->countUnreferencedFiles();
            if ($orphanCount > 0) {
                $orphanStatus = 'findings';
                $this->findings[] = new Finding(
                    'files',
                    'unreferenced',
                    null,
                    'blob',
                    null,
                    null,
                    Finding::REASON_ORPHAN_ENTITY,
                    '',
                    $orphanCount
                );
            }
        } catch (\Throwable $e) {
            $orphanStatus = 'error';
            $note = 'orphan check: ' . $e->getMessage();
            $this->warnings[] = sprintf('Pass C (files) failed: %s', $e->getMessage());
        }
        $this->tableResults['files'] = [
            'kind' => 'orphan_blob',
            'settingsChecked' => [],
            'findingsCount' => $orphanCount,
            'status' => $orphanStatus === 'findings' ? 'findings' : ($orphanStatus === 'error' ? 'error' : 'clean'),
            'note' => $note,
            'orphanCount' => $orphanCount,
            'orphanFk' => 'unreferenced blob (no submission_files / submission_file_revisions)',
            'orphanStatus' => $orphanStatus,
        ];
    }

    /**
     * Pass E — finds submission_files rows stuck in REVIEW_REVISION status
     * (file_stage = 15). These rows block journal/submission deletion with
     * a fatal error in OJS CLI.
     */
    private function runReviewPass(): void
    {
        $status = 'clean';
        $count = 0;
        try {
            $count = $this->gateway->countReviewRevisionFiles();
            if ($count > 0) {
                $this->findings[] = new Finding(
                    'submission_files',
                    Finding::bulkPk('review'),
                    null,
                    'file_stage',
                    null,
                    '15',
                    Finding::REASON_REVIEW_REVISION,
                    '',
                    $count
                );
            }
        } catch (\Throwable $e) {
            $status = 'error';
            $this->warnings[] = sprintf('Pass E failed for submission_files: %s', $e->getMessage());
        }

        $this->tableResults['submission_files_review'] = [
            'kind' => 'review',
            'settingsChecked' => ['file_stage'],
            'findingsCount' => $count,
            'status' => $status === 'error' ? 'error' : ($count > 0 ? 'findings' : 'clean'),
            'note' => $count > 0 ? "found {$count} files under REVIEW_REVISION status" : '',
            'orphanCount' => 0,
            'orphanFk' => null,
            'orphanStatus' => 'skipped',
        ];
    }


    private function runEntityOrphanPass(): void
    {
        $cleaner = new OrphanReferenceCleaner($this->gateway);
        foreach ($cleaner->scan(function (string $table): void {
            $this->reportStep($table, self::SCENARIO_ENTITY_REF);
        }) as $finding) {
            $this->findings[] = $finding;
        }
        foreach ($cleaner->getWarnings() as $warning) {
            $this->warnings[] = $warning;
        }
    }

    /**
     * Per-table result metadata collected during scan(). Includes locale-
     * check and orphan-check statuses, FK descriptors, and finding counts.
     *
     * @return array<string, array{kind:string, settingsChecked:string[], findingsCount:int, status:string, note:string}>
     */
    public function getTableResults(): array
    {
        return $this->tableResults;
    }

    /**
     * Pass F — finds rows still referencing journals that no longer exist.
     *
     * OJS never cleans these up: JournalDAO inherits SchemaDAO::deleteById,
     * which deletes only `journals` and `journal_settings`, and the 3.3 schema
     * declares no FK constraints. Every other journal-scoped table therefore
     * survives its journal.
     *
     * Resolution runs per dead journal so each one can later be deleted inside
     * its own transaction. The cascade plan is walked parents-first, carrying
     * each generation's identity values down to the next.
     */
    private function runDeletedJournalPass(): void
    {
        if ($this->cascadeRegistry === null) {
            $this->warnings[] = 'Pass F skipped: no cascade registry supplied';
            return;
        }

        try {
            $plan = $this->cascadeRegistry->build();
            $deadIds = $this->gateway->findDeadJournalIds($this->cascadeRegistry->getDirectRootColumns());
        } catch (\Throwable $e) {
            $this->warnings[] = sprintf('Pass F failed to build the cascade plan: %s', $e->getMessage());
            return;
        }

        foreach ($this->cascadeRegistry->getWarnings() as $w) {
            $this->warnings[] = $w;
        }

        $tableCounts = [];
        $planByTable = [];
        foreach ($plan as $planStep) {
            $planByTable[$planStep['table']] = $planStep;
        }

        foreach ($plan as $step) {
            $this->reportStep($step['table'], self::SCENARIO_JOURNAL);
            if (empty($deadIds)) {
                continue;
            }

            $table = $step['table'];
            try {
                if ($step['source'] === 'journal') {
                    $count = $this->gateway->countRowsByColumn(
                        $table,
                        $step['column'],
                        $deadIds,
                        $step['assocType']
                    );
                } else {
                    $count = $this->gateway->countRowsByDeadJournalPath($step, $planByTable, $deadIds);
                }
            } catch (\Throwable $e) {
                $this->warnings[] = sprintf('Pass F failed for %s: %s', $table, $e->getMessage());
                continue;
            }

            if ($count === 0) {
                continue;
            }

            $tableCounts[$table] = ($tableCounts[$table] ?? 0) + $count;

            $key = 'deleted_journal:' . $table;
            $prev = $this->tableResults[$key] ?? null;
            $this->tableResults[$key] = [
                'kind' => 'deleted_journal',
                'settingsChecked' => [$step['column']],
                'findingsCount' => ($prev['findingsCount'] ?? 0) + $count,
                'status' => 'findings',
                'note' => $step['via'],
                'orphanCount' => 0,
                'orphanFk' => null,
                'orphanStatus' => 'skipped',
            ];
        }

        foreach ($tableCounts as $table => $count) {
            $this->findings[] = new Finding(
                $table,
                Finding::bulkPk('deleted-journal-table'),
                null,
                'journal_id',
                null,
                (string) count($deadIds) . ' dead journal(s)',
                Finding::REASON_DELETED_JOURNAL,
                '',
                $count
            );
        }

        foreach ($deadIds as $journalId) {
            $this->deadJournalResults[$journalId] = [
                'journalId' => $journalId,
                'tables' => $tableCounts,
                'rows' => array_sum($tableCounts),
            ];
        }
    }

    /**
     * Per-journal results from Pass F: how many leftover rows each dead
     * journal owns, and in which tables.
     *
     * @return array<int, array{journalId:int, tables:array<string,int>, rows:int}>
     */
    public function getDeadJournalResults(): array
    {
        return $this->deadJournalResults;
    }

    /**
     * Top-level scan context: database name, table counts, and how many
     * tables were schema-mapped vs. auto-discovered.
     *
     * @return array{database:string,tablesScanned:int,schemaMapped:int,autoDiscovered:int}
     */
    public function getContextStats(): array
    {
        return $this->contextStats;
    }

    /**
     * Per-entity results from Pass D1 (required-but-null on main tables).
     *
     * @return array<string, array{table:string,pk:string,nullableRequired:string[],findingsCount:int,status:string,note:string}>
     */
    public function getEntityResults(): array
    {
        return $this->entityResults;
    }

    /**
     * Non-fatal warnings collected during scanning (schema parse failures,
     * per-table query errors).
     *
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
