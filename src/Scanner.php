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
 * @brief Runs the two detection passes over a database via DatabaseGateway.
 *        Eager: scan() executes synchronously and returns Finding[]. tableResults
 *        and contextStats are guaranteed populated after scan() returns regardless
 *        of how the caller iterates the results.
 */

namespace APP\tools\settingsHealthCheck\src;

final class Scanner
{
    /** Pass A (schema) + Pass B (heuristic): missing-locale translations. */
    public const CHECK_LOCALE = 'locale';

    /** Pass C: orphaned settings whose parent entity is gone. */
    public const CHECK_ORPHAN = 'orphan';

    /** Pass D1 (required NULL) + Pass D2 (setting_value NULL): empty fields. */
    public const CHECK_EMPTY = 'empty';

    /** Pass E: files with REVIEW_REVISION stage. */
    public const CHECK_REVIEW = 'review';

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

    /** @var string[] */
    private array $unmappedTables = [];

    private string $primaryLocale = 'en';

    private bool $initialized = false;

    /** @var Finding[] */
    private array $findings = [];

    /** @var DatabaseGateway */
    private $gateway;

    public function __construct(DatabaseGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * Resolves database name, primary locale, auto-discovered tables, and
     * pre-populates per-table results with status='pending'.
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

        $this->initialized = true;
    }

    /**
     * Synchronously runs the requested check passes. Returns the collected
     * Finding array. After return, getTableResults() and getContextStats()
     * are fully populated.
     *
     * @param string[]|null $checks Subset of CHECK_* constants to run. Null
     *        runs every check (the historical full-scan behaviour).
     * @return Finding[]
     */
    public function scan(?array $checks = null): array
    {
        if (!$this->initialized) {
            throw new \LogicException('Scanner::initialize() must be called before scan().');
        }

        $checks = $checks ?? [self::CHECK_LOCALE, self::CHECK_ORPHAN, self::CHECK_EMPTY, self::CHECK_REVIEW];
        $run = array_fill_keys($checks, true);

        $this->findings = [];

        // Pass A — schema-driven on mapped tables.
        if (!empty($run[self::CHECK_LOCALE])) {
        foreach ($this->schemaMap as $table => $settingNamesSet) {
            $names = array_keys($settingNamesSet);
            $count = 0;
            $status = 'clean';
            $note = '';
            try {
                foreach ($this->gateway->getMultilingualOffenders($table, $names) as $row) {
                    $count++;
                    $this->findings[] = Finding::fromRow(
                        $table,
                        $row['pk'],
                        $row['fk'] ?? null,
                        (string) $row['setting_name'],
                        $row['locale'] ?? null,
                        $row['setting_value'] ?? null,
                        Finding::REASON_SCHEMA_MISSING_LOCALE,
                        $this->primaryLocale
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
            $count = 0;
            $status = 'clean';
            $note = '';
            $suspects = [];
            try {
                $suspects = $this->gateway->findSuspectSettingNames($table);
                if (!empty($suspects)) {
                    foreach ($this->gateway->getEmptyLocaleRowsForSettings($table, $suspects) as $row) {
                        $count++;
                        $this->findings[] = Finding::fromRow(
                            $table,
                            $row['pk'],
                            $row['fk'] ?? null,
                            (string) $row['setting_name'],
                            $row['locale'] ?? null,
                            $row['setting_value'] ?? null,
                            Finding::REASON_HEURISTIC_LOCALE_MISMATCH,
                            ''
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

        // Pass C — orphan FK detection across every known settings table.
        if (!empty($run[self::CHECK_ORPHAN])) {
            foreach ($this->tableResults as $table => $r) {
                $this->runOrphanPass($table);
            }
        }

        if (!empty($run[self::CHECK_EMPTY])) {
            // Pass D1 — required-but-null on main entity tables.
            foreach ($this->entityMap as $mainTable => $entity) {
                $this->runRequiredNullPass($mainTable, $entity);
            }

            // Pass D2 — setting_value IS NULL on every known settings table.
            foreach ($this->tableResults as $table => $_r) {
                $this->runSettingValueNullPass($table);
            }
        }

        if (!empty($run[self::CHECK_REVIEW])) {
            $this->runReviewPass();
        }

        $this->finalizeTableResults($run);

        return $this->findings;
    }

    /**
     * Resolves leftover per-table state after a partial scan so the report
     * never claims a dimension was checked when its pass was skipped:
     *  - skipped locale pass: clear settingsChecked, note that it was not run;
     *  - skipped orphan pass: mark the orphan sub-status 'skipped' (no line);
     *  - any still-'pending' status collapses to 'clean'.
     *
     * @param array<string, true> $run
     */
    private function finalizeTableResults(array $run): void
    {
        $localeRan = !empty($run[self::CHECK_LOCALE]);
        $orphanRan = !empty($run[self::CHECK_ORPHAN]);

        foreach ($this->tableResults as $table => $r) {
            if ($table === 'submission_files_review') {
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
     * @param array{table:string,pk:string,requiredColumns:string[]} $entity
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
                foreach ($this->gateway->findRowsWithNullColumn($mainTable, $pk, $column) as $row) {
                    $count++;
                    $this->findings[] = Finding::fromRow(
                        $mainTable,
                        $row['pk'],
                        $row['pk'],
                        $column,
                        null,
                        null,
                        Finding::REASON_REQUIRED_NULL,
                        ''
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

    private function runSettingValueNullPass(string $table): void
    {
        $r = $this->tableResults[$table];
        $count = 0;
        try {
            foreach ($this->gateway->findRowsWithNullSettingValue($table) as $row) {
                $count++;
                $this->findings[] = Finding::fromRow(
                    $table,
                    $row['pk'],
                    $row['fk'] ?? null,
                    (string) ($row['setting_name'] ?? ''),
                    $row['locale'] ?? null,
                    null,
                    Finding::REASON_SETTING_VALUE_NULL,
                    ''
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

    private function runOrphanPass(string $table): void
    {
        $r = $this->tableResults[$table];
        $orphanStatus = 'clean';
        $orphanCount = 0;
        $orphanFk = null;
        try {
            $fk = $this->gateway->getForeignKey($table);
            if ($fk === null) {
                $orphanStatus = 'skipped';
                $r['orphanFk'] = null;
            } else {
                $orphanFk = sprintf('%s -> %s(%s)', $fk['column'], $fk['parentTable'], $fk['parentColumn']);
                foreach ($this->gateway->findOrphans($table, $fk['column'], $fk['parentTable'], $fk['parentColumn']) as $row) {
                    $orphanCount++;
                    $this->findings[] = Finding::fromRow(
                        $table,
                        $row['pk'],
                        $row['fk'] ?? null,
                        (string) ($row['setting_name'] ?? ''),
                        $row['locale'] ?? null,
                        $row['setting_value'] ?? null,
                        Finding::REASON_ORPHAN_ENTITY,
                        ''
                    );
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

    private function runReviewPass(): void
    {
        $status = 'clean';
        $count = 0;
        try {
            foreach ($this->gateway->findReviewRevisionFiles() as $row) {
                $count++;
                $this->findings[] = Finding::fromRow(
                    'submission_files',
                    $row['pk'],
                    $row['fk'] ?? null,
                    (string) ($row['setting_name'] ?? ''),
                    $row['locale'] ?? null,
                    $row['setting_value'] ?? null,
                    Finding::REASON_REVIEW_REVISION,
                    ''
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


    /**
     * Backwards-compat alias yielding the eagerly-collected scan() results.
     *
     * @return \Generator<int, Finding>
     */
    public function run(): \Generator
    {
        foreach ($this->scan() as $f) {
            yield $f;
        }
    }

    /**
     * @return array<string, array{kind:string, settingsChecked:string[], findingsCount:int, status:string, note:string}>
     */
    public function getTableResults(): array
    {
        return $this->tableResults;
    }

    /**
     * @return array{database:string,tablesScanned:int,schemaMapped:int,autoDiscovered:int}
     */
    public function getContextStats(): array
    {
        return $this->contextStats;
    }

    /**
     * @return array<string, array{table:string,pk:string,nullableRequired:string[],findingsCount:int,status:string,note:string}>
     */
    public function getEntityResults(): array
    {
        return $this->entityResults;
    }

    /**
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
