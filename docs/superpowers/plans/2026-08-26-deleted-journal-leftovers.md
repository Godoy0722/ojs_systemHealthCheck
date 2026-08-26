# Deleted-Journal Leftovers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a sixth scenario to `tools/settingsHealthCheck` that finds every database row still referencing an already-deleted journal, and deletes those rows on `--fix`.

**Architecture:** A new Pass F in `Scanner` walks a declarative cascade registry (new `JournalCascadeRegistry` class) to resolve, per dead journal id, every leftover row from journal-scoped roots down through their descendant chains. `Fixer` deletes each journal's rows deepest-first inside one transaction per journal. Reporting reuses the existing scenario/bucket machinery unchanged.

**Tech Stack:** PHP 7.4, Illuminate Capsule query builder (no facade root in OJS 3.3), OJS `CommandLineTool`, `require_once` wiring (no APP autoloader in 3.3).

**Reference spec:** `docs/superpowers/specs/2026-08-26-deleted-journal-records-design.md`

---

## Constraints for this plan

These override the writing-plans skill defaults. They are not optional.

1. **No commits.** Do not run `git commit`, `git add`, or `git push` at any point. Leave every change in the working tree. The user reviews and commits themselves.
2. **No test files.** This tool has no test suite and no phpunit in the repo. Do not create one. Verification is `php -l` per task plus the staged manual run in Task 9.
3. **Follow existing conventions exactly.** Every `src/` class: OJS file-doc header with `@file`, `@class`, `@brief`; `final class`; typed private properties; full docblocks on public methods; `\Throwable` caught and degraded to a safe empty result rather than thrown; `tableExists()` guard before every query.

## Deviation from the spec, deliberate

The spec named three gateway methods: `findDeadJournalIds()`, `findJournalScopedRows()`, `deleteJournalCascade()`. This plan implements the first, and splits the other two into four smaller composable primitives — `columnExists()`, `findRowIdsByColumn()`, `deleteRowsByColumn()`, `runInTransaction()`. Reason: cascade *walking* is registry-shaped logic that belongs in `Scanner`/`Fixer`, not in the gateway, and the smaller primitives keep the gateway a pure data-access layer like every existing method on it. Behaviour and transaction boundaries are exactly as approved.

## File structure

| File | Action | Responsibility |
|---|---|---|
| `src/Finding.php` | Modify | Add one reason constant |
| `src/JournalCascadeRegistry.php` | **Create** | Declarative journal→table cascade map; validates itself against the live schema |
| `src/IlluminateDatabaseGateway.php` | Modify | Four new data-access primitives |
| `src/Scanner.php` | Modify | Pass F: resolve leftover rows per dead journal |
| `src/Fixer.php` | Modify | Delete per journal, deepest-first, in a transaction |
| `src/ReportWriter.php` | Modify | Scenario 7, menu bounds, slug, explanation text |
| `settingsHealthCheck.php` | Modify | `-d` flag, wiring, confirmation gate, fix summary lines |
| `README.md` | Modify | Document the flag, the scenario, and the reason code |

---

### Task 1: Add the reason constant

**Files:**
- Modify: `src/Finding.php:24`

- [ ] **Step 1: Add the constant**

In `src/Finding.php`, directly after the `REASON_REVIEW_REVISION` line, add:

```php
    public const REASON_DELETED_JOURNAL = 'deleted_journal';
```

The constant block then reads:

```php
    public const REASON_SCHEMA_MISSING_LOCALE = 'schema_missing_locale';
    public const REASON_HEURISTIC_LOCALE_MISMATCH = 'heuristic_locale_mismatch';
    public const REASON_ORPHAN_ENTITY = 'orphan_entity';
    public const REASON_REQUIRED_NULL = 'required_null';
    public const REASON_SETTING_VALUE_NULL = 'setting_value_null';
    public const REASON_REVIEW_REVISION = 'review_revision';
    public const REASON_DELETED_JOURNAL = 'deleted_journal';
```

- [ ] **Step 2: Lint**

Run: `php -l tools/settingsHealthCheck/src/Finding.php`
Expected: `No syntax errors detected in tools/settingsHealthCheck/src/Finding.php`

---

### Task 2: Create the cascade registry

**Files:**
- Create: `src/JournalCascadeRegistry.php`

This class holds the map and nothing else — it performs no queries of its own. It takes the gateway only to verify that each declared table and column actually exists, so a wrong guess in the map degrades to a warning instead of a fatal error or, worse, a delete against the wrong column.

- [ ] **Step 1: Create the file**

```php
<?php

/**
 * @file tools/SettingsHealthCheck/JournalCascadeRegistry.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class JournalCascadeRegistry
 *
 * @brief Declarative map of every table that holds journal-scoped data, plus
 *        the descendant chains that become orphaned when a journal row is
 *        deleted. OJS never cascades these: JournalDAO inherits
 *        SchemaDAO::deleteById, which removes only `journals` and
 *        `journal_settings`, and the 3.3 schema declares no FK constraints.
 *
 *        build() returns a resolution plan ordered parents-first. Deleting
 *        walks the same plan reversed, so children always go before parents.
 *        Every declared table/column is verified against the live schema;
 *        anything missing is dropped from the plan and reported via
 *        getWarnings() rather than throwing.
 */

namespace APP\tools\settingsHealthCheck\src;

final class JournalCascadeRegistry
{
    /** ASSOC_TYPE_JOURNAL — 0x0000100, see classes/core/Application.inc.php. */
    public const ASSOC_TYPE_JOURNAL = 256;

    /**
     * Tables carrying a direct journal reference.
     * table => [journal column, row-identity column]
     *
     * The identity column is the table's own primary key where it has one.
     * Composite-key settings tables (OJS 3.3 has no surrogate ids on those)
     * reuse the journal column as their identity, matching how Finding::$pk
     * is already populated for composite tables elsewhere in this tool.
     */
    public const DEFAULT_DIRECT_ROOTS = [
        'journal_settings'      => ['journal_id', 'journal_id'],
        'sections'              => ['journal_id', 'section_id'],
        'issues'                => ['journal_id', 'issue_id'],
        'custom_issue_orders'   => ['journal_id', 'issue_id'],
        'submission_tombstones' => ['journal_id', 'tombstone_id'],
        'subscription_types'    => ['journal_id', 'type_id'],
        'subscriptions'         => ['journal_id', 'subscription_id'],
        'submissions'           => ['context_id', 'submission_id'],
        'user_groups'           => ['context_id', 'user_group_id'],
        'user_group_stage'      => ['context_id', 'user_group_id'],
        'categories'            => ['context_id', 'category_id'],
        'genres'                => ['context_id', 'genre_id'],
        'library_files'         => ['context_id', 'file_id'],
        'navigation_menus'      => ['context_id', 'navigation_menu_id'],
        'navigation_menu_items' => ['context_id', 'navigation_menu_item_id'],
        'plugin_settings'       => ['context_id', 'plugin_name'],
        'filters'               => ['context_id', 'filter_id'],
        'metrics'               => ['context_id', 'metric_id'],
        'notifications'         => ['context_id', 'notification_id'],
        'email_templates'       => ['context_id', 'email_id'],
        'completed_payments'    => ['context_id', 'completed_payment_id'],
    ];

    /**
     * Tables referencing a journal polymorphically via assoc_type + assoc_id.
     * table => row-identity column
     */
    public const DEFAULT_ASSOC_ROOTS = [
        'announcements'                        => 'announcement_id',
        'announcement_types'                   => 'type_id',
        'review_forms'                         => 'review_form_id',
        'data_object_tombstone_oai_set_objects' => 'object_id',
    ];

    /**
     * Descendant chains, keyed by parent table.
     * parent => [parent identity column, [[child table, child FK column], ...]]
     *
     * A table appears exactly once across DIRECT_ROOTS, ASSOC_ROOTS and the
     * child lists here. Tables that carry their own journal column
     * (user_group_stage, submission_tombstones) are roots, not descendants,
     * so they stay reachable when their nominal parent row is already gone.
     */
    public const DEFAULT_DESCENDANTS = [
        'sections' => ['section_id', [
            ['section_settings', 'section_id'],
        ]],
        'issues' => ['issue_id', [
            ['issue_settings', 'issue_id'],
            ['issue_files', 'issue_id'],
            ['custom_section_orders', 'issue_id'],
            ['issue_galleys', 'issue_id'],
        ]],
        'issue_galleys' => ['galley_id', [
            ['issue_galley_settings', 'galley_id'],
        ]],
        'submissions' => ['submission_id', [
            ['submission_settings', 'submission_id'],
            ['publications', 'submission_id'],
            ['edit_decisions', 'submission_id'],
            ['review_rounds', 'submission_id'],
            ['review_assignments', 'submission_id'],
            ['stage_assignments', 'submission_id'],
            ['submission_files', 'submission_id'],
            ['submission_comments', 'submission_id'],
            ['submission_search_objects', 'submission_id'],
        ]],
        'publications' => ['publication_id', [
            ['publication_settings', 'publication_id'],
            ['publication_galleys', 'publication_id'],
            ['authors', 'publication_id'],
            ['citations', 'publication_id'],
            ['publication_categories', 'publication_id'],
        ]],
        'publication_galleys' => ['galley_id', [
            ['publication_galley_settings', 'galley_id'],
        ]],
        'authors' => ['author_id', [
            ['author_settings', 'author_id'],
        ]],
        'citations' => ['citation_id', [
            ['citation_settings', 'citation_id'],
        ]],
        'review_rounds' => ['review_round_id', [
            ['review_round_files', 'review_round_id'],
        ]],
        'review_assignments' => ['review_id', [
            ['review_files', 'review_id'],
        ]],
        'submission_files' => ['submission_file_id', [
            ['submission_file_settings', 'submission_file_id'],
        ]],
        'user_groups' => ['user_group_id', [
            ['user_group_settings', 'user_group_id'],
            ['user_user_groups', 'user_group_id'],
        ]],
        'categories' => ['category_id', [
            ['category_settings', 'category_id'],
        ]],
        'genres' => ['genre_id', [
            ['genre_settings', 'genre_id'],
        ]],
        'navigation_menu_items' => ['navigation_menu_item_id', [
            ['navigation_menu_item_settings', 'navigation_menu_item_id'],
            ['navigation_menu_item_assignments', 'navigation_menu_item_id'],
        ]],
        'email_templates' => ['email_id', [
            ['email_templates_settings', 'email_id'],
        ]],
        'review_forms' => ['review_form_id', [
            ['review_form_settings', 'review_form_id'],
            ['review_form_elements', 'review_form_id'],
        ]],
        'review_form_elements' => ['review_form_element_id', [
            ['review_form_element_settings', 'review_form_element_id'],
            ['review_form_responses', 'review_form_element_id'],
        ]],
        'announcements' => ['announcement_id', [
            ['announcement_settings', 'announcement_id'],
        ]],
        'announcement_types' => ['type_id', [
            ['announcement_type_settings', 'type_id'],
        ]],
        'subscriptions' => ['subscription_id', [
            ['institutional_subscriptions', 'subscription_id'],
        ]],
        'institutional_subscriptions' => ['subscription_id', [
            ['institutional_subscription_ip', 'subscription_id'],
        ]],
        'subscription_types' => ['type_id', [
            ['subscription_type_settings', 'type_id'],
        ]],
        'notifications' => ['notification_id', [
            ['notification_settings', 'notification_id'],
        ]],
    ];

    /** @var IlluminateDatabaseGateway */
    private $gateway;

    /** @var array<string, array{0:string,1:string}> */
    private array $directRoots;

    /** @var array<string, string> */
    private array $assocRoots;

    /** @var array<string, array{0:string,1:array<int,array{0:string,1:string}>}> */
    private array $descendants;

    /** @var string[] */
    private array $warnings = [];

    /** @var array<int, array<string, mixed>> */
    private array $plan = [];

    /** @var bool Idempotency guard — build() runs only once. */
    private bool $built = false;

    /**
     * @param array<string, array{0:string,1:string}> $directRoots table => [journal column, identity column]
     * @param array<string, string> $assocRoots table => identity column
     * @param array<string, array{0:string,1:array<int,array{0:string,1:string}>}> $descendants parent => [identity column, children]
     */
    public function __construct(
        IlluminateDatabaseGateway $gateway,
        array $directRoots = self::DEFAULT_DIRECT_ROOTS,
        array $assocRoots = self::DEFAULT_ASSOC_ROOTS,
        array $descendants = self::DEFAULT_DESCENDANTS
    ) {
        $this->gateway = $gateway;
        $this->directRoots = $directRoots;
        $this->assocRoots = $assocRoots;
        $this->descendants = $descendants;
    }

    /**
     * Builds the resolution plan, parents before children. Each step is:
     *   table    — the table to read or delete from
     *   identity — column identifying one row, used for reporting and for
     *              resolving that table's own children
     *   source   — 'journal' (matched against dead journal ids) or 'parent'
     *   column   — journal column (source=journal) or FK column (source=parent)
     *   parent   — parent table name, null for roots
     *   assocType— ASSOC_TYPE_JOURNAL for polymorphic roots, else null
     *   depth    — 0 for roots, +1 per generation
     *   via      — human-readable chain, e.g. "submissions > publications"
     *
     * Tables or columns absent from the live schema are skipped with a
     * warning; their children are skipped too, since they are unreachable.
     * Idempotent — subsequent calls return the cached plan.
     *
     * @return array<int, array{table:string, identity:string, source:string, column:string, parent:?string, assocType:?int, depth:int, via:string}>
     */
    public function build(): array
    {
        if ($this->built) {
            return $this->plan;
        }
        $this->built = true;

        $seen = [];

        foreach ($this->directRoots as $table => $cols) {
            [$journalColumn, $identity] = $cols;
            if (!$this->verify($table, [$journalColumn, $identity])) {
                continue;
            }
            $this->plan[] = [
                'table' => $table,
                'identity' => $identity,
                'source' => 'journal',
                'column' => $journalColumn,
                'parent' => null,
                'assocType' => null,
                'depth' => 0,
                'via' => $table,
            ];
            $seen[$table] = true;
            $this->appendChildren($table, $table, 1, $seen);
        }

        foreach ($this->assocRoots as $table => $identity) {
            if (!$this->verify($table, ['assoc_type', 'assoc_id', $identity])) {
                continue;
            }
            $this->plan[] = [
                'table' => $table,
                'identity' => $identity,
                'source' => 'journal',
                'column' => 'assoc_id',
                'parent' => null,
                'assocType' => self::ASSOC_TYPE_JOURNAL,
                'depth' => 0,
                'via' => $table,
            ];
            $seen[$table] = true;
            $this->appendChildren($table, $table, 1, $seen);
        }

        return $this->plan;
    }

    /**
     * Appends every descendant of $parent to the plan, depth-first, skipping
     * tables already present so a table is never visited twice.
     *
     * @param string $parent Parent table name
     * @param string $via Chain description accumulated so far
     * @param int $depth Generation counter, 1 for direct children
     * @param array<string, true> $seen Tables already placed in the plan
     */
    private function appendChildren(string $parent, string $via, int $depth, array &$seen): void
    {
        if (!isset($this->descendants[$parent])) {
            return;
        }
        [$parentIdentity, $children] = $this->descendants[$parent];
        if (!$this->gateway->columnExists($parent, $parentIdentity)) {
            $this->warnings[] = sprintf(
                'cascade: %s has no column %s — its children cannot be resolved',
                $parent,
                $parentIdentity
            );
            return;
        }
        foreach ($children as $child) {
            [$childTable, $childFk] = $child;
            if (isset($seen[$childTable])) {
                $this->warnings[] = sprintf('cascade: %s reached twice, keeping the first path', $childTable);
                continue;
            }
            $childIdentity = isset($this->descendants[$childTable])
                ? $this->descendants[$childTable][0]
                : $childFk;
            if (!$this->verify($childTable, [$childFk])) {
                continue;
            }
            $this->plan[] = [
                'table' => $childTable,
                'identity' => $childIdentity,
                'source' => 'parent',
                'column' => $childFk,
                'parent' => $parent,
                'assocType' => null,
                'depth' => $depth,
                'via' => $via . ' > ' . $childTable,
            ];
            $seen[$childTable] = true;
            $this->appendChildren($childTable, $via . ' > ' . $childTable, $depth + 1, $seen);
        }
    }

    /**
     * Confirms a table and every named column exist in the live schema,
     * recording a warning for whatever is missing.
     *
     * @param string $table
     * @param string[] $columns
     * @return bool True when the table and all columns are present
     */
    private function verify(string $table, array $columns): bool
    {
        if (!$this->gateway->tableExists($table)) {
            $this->warnings[] = sprintf('cascade: table %s not present in this database, skipped', $table);
            return false;
        }
        foreach ($columns as $column) {
            if (!$this->gateway->columnExists($table, $column)) {
                $this->warnings[] = sprintf('cascade: %s has no column %s, skipped', $table, $column);
                return false;
            }
        }
        return true;
    }

    /**
     * The journal-referencing root tables that survived schema verification,
     * as table => journal column. Used to scan for dead journal ids.
     *
     * @return array<string, string>
     */
    public function getDirectRootColumns(): array
    {
        $out = [];
        foreach ($this->build() as $step) {
            if ($step['source'] === 'journal' && $step['assocType'] === null) {
                $out[$step['table']] = $step['column'];
            }
        }
        return $out;
    }

    /**
     * Warnings collected during build (tables or columns absent from this
     * database, duplicate paths).
     *
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
```

- [ ] **Step 2: Lint**

Run: `php -l tools/settingsHealthCheck/src/JournalCascadeRegistry.php`
Expected: `No syntax errors detected`

---

### Task 3: Add the gateway primitives

**Files:**
- Modify: `src/IlluminateDatabaseGateway.php`

Note `tableExists()` is currently `private` (line 532). It becomes `public` because the registry calls it.

- [ ] **Step 1: Make `tableExists()` public**

Change the signature at `src/IlluminateDatabaseGateway.php:532` from:

```php
    private function tableExists(string $table): bool
```

to:

```php
    public function tableExists(string $table): bool
```

Also update its docblock's first line from `Quick existence check against the Illuminate schema builder.` to:

```php
    /**
     * Quick existence check against the Illuminate schema builder. Public
     * because JournalCascadeRegistry verifies its map against the live schema.
     *
     * @param string $table
     * @return bool
     */
```

- [ ] **Step 2: Add the chunk constant**

At the top of the class, immediately before the `$tableMetaCache` property declaration (`src/IlluminateDatabaseGateway.php:26`), add:

```php
    /** Maximum ids per WHERE IN clause, to keep statements inside driver limits. */
    private const ID_CHUNK = 500;

```

- [ ] **Step 3: Add the four new methods**

Append these inside the class, after `deleteReviewRevisionFile()` and before the closing brace:

```php
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
```

Note `deleteRowsByColumn()` deliberately does **not** swallow throwables — the caller wraps it in `runInTransaction()` and needs the failure to trigger a rollback.

- [ ] **Step 4: Lint**

Run: `php -l tools/settingsHealthCheck/src/IlluminateDatabaseGateway.php`
Expected: `No syntax errors detected`

---

### Task 4: Add Pass F to the scanner

**Files:**
- Modify: `src/Scanner.php`

- [ ] **Step 1: Add the check constant**

After the `CHECK_REVIEW` constant (`src/Scanner.php:32`), add:

```php
    /** Pass F: rows left behind by an already-deleted journal. */
    public const CHECK_JOURNAL = 'journal';
```

- [ ] **Step 2: Add the registry property and per-journal result store**

After the `private array $entityResults = [];` block (`src/Scanner.php:54`), add:

```php
    /** @var JournalCascadeRegistry|null */
    private $cascadeRegistry = null;

    /** @var array<int, array{journalId:int, tables:array<string,int>, rows:int}> */
    private array $deadJournalResults = [];
```

- [ ] **Step 3: Accept the registry in the constructor**

Replace the constructor (`src/Scanner.php:72-75`) with:

```php
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
```

- [ ] **Step 4: Run the pass from `scan()`**

Replace the default-checks line (`src/Scanner.php:141`) with:

```php
        $checks = $checks ?? [self::CHECK_LOCALE, self::CHECK_ORPHAN, self::CHECK_EMPTY, self::CHECK_REVIEW, self::CHECK_JOURNAL];
```

Then, immediately after the `CHECK_REVIEW` block (`src/Scanner.php:255-257`) and before `$this->finalizeTableResults($run);`, add:

```php
        if (!empty($run[self::CHECK_JOURNAL])) {
            $this->runDeletedJournalPass();
        }
```

- [ ] **Step 5: Exempt Pass F's synthetic rows from `finalizeTableResults()`**

Pass F writes its own `tableResults` entries keyed `deleted_journal:<table>`. `finalizeTableResults()` must leave them alone, exactly as it already does for `submission_files_review`. Replace the guard at `src/Scanner.php:279-281`:

```php
            if ($table === 'submission_files_review') {
                continue;
            }
```

with:

```php
            if ($table === 'submission_files_review' || strpos($table, 'deleted_journal:') === 0) {
                continue;
            }
```

- [ ] **Step 6: Implement the pass**

Add this method after `runReviewPass()` (`src/Scanner.php:491`):

```php
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

        if (empty($deadIds)) {
            return;
        }

        foreach ($deadIds as $journalId) {
            $idsByTable = [];
            $rowCount = 0;
            $tables = [];

            foreach ($plan as $step) {
                $table = $step['table'];
                try {
                    if ($step['source'] === 'journal') {
                        $ids = $this->gateway->findRowIdsByColumn(
                            $table,
                            $step['identity'],
                            $step['column'],
                            [$journalId],
                            $step['assocType']
                        );
                    } else {
                        $parentIds = $idsByTable[$step['parent']] ?? [];
                        if (empty($parentIds)) {
                            continue;
                        }
                        $ids = $this->gateway->findRowIdsByColumn(
                            $table,
                            $step['identity'],
                            $step['column'],
                            $parentIds,
                            null
                        );
                    }
                } catch (\Throwable $e) {
                    $this->warnings[] = sprintf('Pass F failed for %s (journal %d): %s', $table, $journalId, $e->getMessage());
                    continue;
                }

                if (empty($ids)) {
                    continue;
                }
                $idsByTable[$table] = $ids;

                $count = count($ids);
                $rowCount += $count;
                $tables[$table] = $count;

                foreach ($ids as $id) {
                    $this->findings[] = new Finding(
                        $table,
                        $id,
                        $journalId,
                        $step['column'],
                        null,
                        $step['via'],
                        Finding::REASON_DELETED_JOURNAL,
                        ''
                    );
                }

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

            $this->deadJournalResults[$journalId] = [
                'journalId' => $journalId,
                'tables' => $tables,
                'rows' => $rowCount,
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
```

- [ ] **Step 7: Lint**

Run: `php -l tools/settingsHealthCheck/src/Scanner.php`
Expected: `No syntax errors detected`

---

### Task 5: Teach the fixer to delete the cascade

**Files:**
- Modify: `src/Fixer.php`

The fixer currently loops findings one at a time. Deleted-journal findings need journal-level grouping and a transaction boundary, so they are partitioned out and handled before the rest — which is also what makes an already-deleted orphan row count as `alreadyRemoved` instead of `failed`.

- [ ] **Step 1: Update the class docblock**

Replace lines 12-21 of `src/Fixer.php` (the `@brief` block) with:

```php
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
```

- [ ] **Step 2: Add the registry dependency**

Replace the constructor (`src/Fixer.php:36-44`) with:

```php
    /** @var JournalCascadeRegistry|null */
    private $cascadeRegistry;

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
```

- [ ] **Step 3: Replace `fix()` with the partitioned version**

Replace the whole `fix()` method (`src/Fixer.php:46-115`) with:

```php
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
```

- [ ] **Step 4: Lint**

Run: `php -l tools/settingsHealthCheck/src/Fixer.php`
Expected: `No syntax errors detected`

---

### Task 6: Add scenario 7 to the report

**Files:**
- Modify: `src/ReportWriter.php`

- [ ] **Step 1: Register the scenario**

In the `SCENARIOS` constant, after the `6 =>` entry (`src/ReportWriter.php:104-107`), add:

```php
        7 => [
            'label'   => 'Deleted journal leftovers',
            'reasons' => [Finding::REASON_DELETED_JOURNAL],
        ],
```

- [ ] **Step 2: Widen the menu bounds**

In `interactiveLoop()`, replace the prompt at `src/ReportWriter.php:278`:

```php
            echo $c('  Enter [1-6] to see details, [q] to quit: ', 'bold');
```

with:

```php
            echo $c('  Enter [1-7] to see details, [q] to quit: ', 'bold');
```

and replace the bounds check at `src/ReportWriter.php:289-292`:

```php
            if ($n < 1 || $n > 6) {
                echo '  ' . $c('Invalid choice. Enter a number 1–6 or "q".', 'yellow') . "\n\n";
                continue;
            }
```

with:

```php
            if ($n < 1 || $n > 7) {
                echo '  ' . $c('Invalid choice. Enter a number 1–7 or "q".', 'yellow') . "\n\n";
                continue;
            }
```

- [ ] **Step 3: Add the export slug**

In `scenarioSlug()`, add to the `$slugs` array after `6 => 'review_revision',`:

```php
            7 => 'deleted_journal',
```

- [ ] **Step 4: Add the explanation**

In `describeReason()`, add this case before `default:` (`src/ReportWriter.php:530`):

```php
            case Finding::REASON_DELETED_JOURNAL:
                return 'This row belongs to journal ' . (string) $f->entityId . ', which no longer exists. OJS deletes only the journals and journal_settings rows, so everything else it owned was left behind.';
```

- [ ] **Step 5: Show the cascade chain in the detail view**

Pass F puts the chain (e.g. `submissions > publications`) in `valuePreview`. The existing `Value :` line already prints it, but labelled misleadingly. In `renderScenarioDetail()`, replace the value block (`src/ReportWriter.php:383-385`):

```php
                if ($f->valuePreview !== '') {
                    echo '      ' . $c('Value', 'dim') . '   : ' . $this->truncate($f->valuePreview, 100) . "\n";
                }
```

with:

```php
                if ($f->valuePreview !== '') {
                    $valueLabel = $f->reason === Finding::REASON_DELETED_JOURNAL ? 'Via' : 'Value';
                    echo '      ' . $c($valueLabel, 'dim') . str_repeat(' ', 7 - mb_strlen($valueLabel)) . ': ' . $this->truncate($f->valuePreview, 100) . "\n";
                }
```

Apply the same change in `saveScenarioToFile()` (`src/ReportWriter.php:452-454`):

```php
                if ($f->valuePreview !== '') {
                    $lines[] = '      Value   : ' . $this->truncate($f->valuePreview, 100);
                }
```

becomes:

```php
                if ($f->valuePreview !== '') {
                    $valueLabel = $f->reason === Finding::REASON_DELETED_JOURNAL ? 'Via' : 'Value';
                    $lines[] = '      ' . str_pad($valueLabel, 7) . ' : ' . $this->truncate($f->valuePreview, 100);
                }
```

- [ ] **Step 6: Lint**

Run: `php -l tools/settingsHealthCheck/src/ReportWriter.php`
Expected: `No syntax errors detected`

---

### Task 7: Wire up the CLI

**Files:**
- Modify: `settingsHealthCheck.php`

- [ ] **Step 1: Require and import the registry**

After the `require_once` for `SchemaRegistry.php` (`settingsHealthCheck.php:29`), add:

```php
require_once(dirname(__FILE__) . '/src/JournalCascadeRegistry.php');
```

After the `use` for `IlluminateDatabaseGateway` (`settingsHealthCheck.php:36`), add:

```php
use APP\tools\settingsHealthCheck\src\JournalCascadeRegistry;
```

- [ ] **Step 2: Add the flag**

In `argumentWrapper()`, add this case before the `-a` case (`settingsHealthCheck.php:204`):

```php
                case '-d':
                case '--deleted-journal':
                    $selected[Scanner::CHECK_JOURNAL] = true;
                    break;
```

and add to the `-a` / `--all` case body, after `$selected[Scanner::CHECK_REVIEW] = true;`:

```php
                    $selected[Scanner::CHECK_JOURNAL] = true;
```

- [ ] **Step 3: Update the usage text**

In `usage()`, add after the `-r, --review` line:

```
            -d, --deleted-journal  Only rows left behind by deleted journals
```

and extend the Fix paragraph so it ends with:

```
                            Deleted-journal findings delete every leftover row
                            belonging to that journal, after the same multiple
                            confirmation prompts.
```

- [ ] **Step 4: Build the registry and pass it through**

In `execute()`, replace lines 125-127:

```php
            $gateway = new IlluminateDatabaseGateway();
            $scanner = new Scanner($gateway);
            $writer = new ReportWriter();
```

with:

```php
            $gateway = new IlluminateDatabaseGateway();
            $cascadeRegistry = new JournalCascadeRegistry($gateway);
            $scanner = new Scanner($gateway, $cascadeRegistry);
            $writer = new ReportWriter();
```

- [ ] **Step 5: Gate the destructive fix**

Replace the fix block (`settingsHealthCheck.php:145-163`) with:

```php
            if ($this->fix) {
                $reviewFindingsCount = 0;
                $journalFindingsCount = 0;
                $deadJournals = [];
                foreach ($allFindings as $f) {
                    if ($f->reason === Finding::REASON_REVIEW_REVISION) {
                        $reviewFindingsCount++;
                    } elseif ($f->reason === Finding::REASON_DELETED_JOURNAL) {
                        $journalFindingsCount++;
                        $deadJournals[(int) $f->entityId] = true;
                    }
                }

                if ($reviewFindingsCount > 0) {
                    $this->confirmReviewFix($reviewFindingsCount);
                }

                if ($journalFindingsCount > 0) {
                    $this->confirmJournalFix(count($deadJournals), $journalFindingsCount);
                }

                $fixer = new Fixer($gateway, $cascadeRegistry);
                $fixResult = $fixer->fix($allFindings);
                foreach ($fixer->getWarnings() as $w) {
                    fwrite(STDERR, ReportWriter::color("[WARN]", 'bold|yellow') . " {$w}\n");
                }
                echo $this->renderFixSummary($fixResult);
            }
```

- [ ] **Step 6: Add the confirmation gate**

`confirmReviewFix()` and this new method share the TTY check and the three stages, differing only in the warning text. Extract the shared body so the two gates cannot drift apart. Replace `confirmReviewFix()` (`settingsHealthCheck.php:231-263`) with:

```php
    /**
     * Three-stage interactive confirmation before deleting review-revision
     * files. Exits the process immediately if any stage is declined.
     *
     * @param int $count Number of REVIEW_REVISION files about to be deleted
     */
    private function confirmReviewFix(int $count): void
    {
        $this->confirmDestructiveFix([
            "WARNING: The scan found {$count} file(s) under the REVIEW_REVISION status.",
            'Fixing these findings will permanently delete these files and their database records.',
        ]);
    }

    /**
     * Three-stage interactive confirmation before deleting the leftovers of
     * journals that no longer exist. Exits the process immediately if any
     * stage is declined.
     *
     * @param int $journalCount Number of deleted journals with leftover rows
     * @param int $rowCount Total rows about to be deleted
     */
    private function confirmJournalFix(int $journalCount, int $rowCount): void
    {
        $this->confirmDestructiveFix([
            "WARNING: The scan found {$rowCount} row(s) belonging to {$journalCount} deleted journal(s).",
            'Fixing these findings will permanently delete every one of those rows,',
            'including submissions, publications, issues and their descendants.',
        ]);
    }

    /**
     * Shared three-stage confirmation used by every destructive fix. Refuses
     * to run without a real terminal, then requires awareness, a second
     * confirmation, and the literal word DELETE. Exits the process on any
     * declined stage.
     *
     * @param string[] $warningLines Scenario-specific warning text
     */
    private function confirmDestructiveFix(array $warningLines): void
    {
        if (!(function_exists('stream_isatty') && stream_isatty(STDIN))) {
            fwrite(STDERR, ReportWriter::color("[ERROR]", 'bold|red') . " Refusing --fix with piped input. Run interactively with a real terminal.\n");
            exit(2);
        }

        echo "\n";
        echo ReportWriter::color("================================================================================\n", 'bold|red');
        foreach ($warningLines as $line) {
            echo ReportWriter::color($line . "\n", 'bold|red');
        }
        echo ReportWriter::color("================================================================================\n\n", 'bold|red');

        echo "Stage 1/3: Are you aware that this operation will delete data in the database? (yes/no): ";
        if (strtolower(trim(fgets(STDIN))) !== 'yes') {
            echo ReportWriter::color("Aborted: User did not confirm awareness of database deletion.\n", 'yellow');
            exit(1);
        }

        echo "Stage 2/3: Do you really want to execute this operation in the database? This is your second confirmation. (yes/no): ";
        if (strtolower(trim(fgets(STDIN))) !== 'yes') {
            echo ReportWriter::color("Aborted: User did not provide the second confirmation.\n", 'yellow');
            exit(1);
        }

        echo "Stage 3/3: This is the final confirmation. This will permanently delete files and database records. Confirm by typing 'DELETE': ";
        if (trim(fgets(STDIN)) !== 'DELETE') {
            echo ReportWriter::color("Aborted: Final confirmation mismatch.\n", 'yellow');
            exit(1);
        }

        echo ReportWriter::color("\nConfirmation successful. Moving forward with the execution...\n\n", 'green');
    }
```

- [ ] **Step 7: Extend the fix summary**

Replace `renderFixSummary()` (`settingsHealthCheck.php:270-286`) with:

```php
    /**
     * Formats the fix result counters as a compact text block shown after --fix.
     *
     * @param array{orphansDeleted:int, localesFixed:int, reviewFilesDeleted:int, journalRecordsDeleted:int, alreadyRemoved:int, skipped:int, failed:int} $r
     */
    private function renderFixSummary(array $r): string
    {
        $c = fn(string $t, string $clr) => ReportWriter::color($t, $clr);

        $lines = [];
        $lines[] = '';
        $lines[] = '  ' . $c('Fixes applied', 'bold');
        $lines[] = '  ' . $c('-------------', 'bold');
        $lines[] = sprintf('  Orphaned rows deleted : %s', $c((string) $r['orphansDeleted'], 'green'));
        $lines[] = sprintf('  Missing locales set   : %s', $c((string) $r['localesFixed'], 'green'));
        $lines[] = sprintf('  Review files deleted  : %s', $c((string) $r['reviewFilesDeleted'], 'green'));
        $lines[] = sprintf('  Journal rows deleted  : %s', $c((string) $r['journalRecordsDeleted'], 'green'));
        $lines[] = sprintf('  Empty fields skipped  : %s  (no auto-fix yet)', $c((string) $r['skipped'], 'yellow'));
        if ($r['alreadyRemoved'] > 0) {
            $lines[] = sprintf('  Already removed       : %s  (deleted by the journal cascade)', $c((string) $r['alreadyRemoved'], 'dim'));
        }
        if ($r['failed'] > 0) {
            $lines[] = sprintf('  Failed                : %s  (see warnings above)', $c((string) $r['failed'], 'red'));
        }
        return implode("\n", $lines) . "\n";
    }
```

- [ ] **Step 8: Lint**

Run: `php -l tools/settingsHealthCheck/settingsHealthCheck.php`
Expected: `No syntax errors detected`

---

### Task 8: Update the README

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Add the flag row**

In the Flags table, after the `-r`, `--review` row:

```markdown
| `-d`, `--deleted-journal` | Deleted journal leftovers — rows still referencing a journal that no longer exists |
```

- [ ] **Step 2: Add the fix-mode row**

In the Fix mode table, after the Review revision files row:

```markdown
| Deleted journal leftovers | Every leftover row is **deleted**, deepest table first, one transaction per journal, after 3-stage confirmation |
```

- [ ] **Step 3: Add the scenario explanation**

In "What It Does", after item 4 (Review revision files), insert:

```markdown
5. **Deleted journal leftovers** — Rows still referencing a journal that no longer exists. `JournalDAO` does not override `deleteById`, so it inherits `SchemaDAO::deleteById`, which deletes only the `journals` and `journal_settings` rows. Sections, issues, submissions and their whole descendant trees, subscriptions, plugin settings, metrics and notifications are all left behind, and the OJS 3.3 schema declares no foreign keys to cascade them. This check walks the full cascade and, on `--fix`, removes it.
```

Renumber the following item (Untracked tables) from 5 to 6.

- [ ] **Step 4: Add the reason-code row**

In the Finding Reasons Reference table, after the `review_revision` row:

```markdown
| `deleted_journal` | **High** | Row belongs to a journal that no longer exists — OJS leaves these behind on journal deletion |
```

- [ ] **Step 5: Update the sample summary table**

In both example output blocks, add a seventh row after the REVIEW_REVISION line and update the prompt line from `Enter [1-6]` to `Enter [1-7]`. First block:

```
│  7  Deleted journal leftovers                       9      417 │
```

Second block (the smaller example):

```
│  7  Deleted journal leftovers                       0        0 │
```

- [ ] **Step 6: Add to the recommended workflow**

After step 5 in Recommended Workflow:

```markdown
6. **Clean up deleted journals last** — `php tools/settingsHealthCheck/settingsHealthCheck.php --deleted-journal --fix` (requires 3 confirmations; removes whole submission trees)
```

---

### Task 9: Manual verification

No test files, so this task is the verification. Run it against a **scratch copy** of a real database, never production.

- [ ] **Step 1: Lint everything**

Run: `php -l tools/settingsHealthCheck/settingsHealthCheck.php && for f in tools/settingsHealthCheck/src/*.php; do php -l "$f"; done`
Expected: `No syntax errors detected` for all seven files.

- [ ] **Step 2: Confirm usage text**

Run: `php tools/settingsHealthCheck/settingsHealthCheck.php --help`
Expected: the help lists `-d, --deleted-journal`, and mentions deleted-journal findings in the Fix paragraph.

- [ ] **Step 3: Read-only scan**

Run: `php tools/settingsHealthCheck/settingsHealthCheck.php --deleted-journal`
Expected: the summary table shows seven rows; scenario 7 has a count. Any `[WARN] cascade: ...` lines on stderr name tables or columns absent from this schema — read them, they reveal wrong column guesses in the registry, and fix the map before going further.

- [ ] **Step 4: Verify a sample by hand**

Drill into scenario 7, press `s` to export, then pick one reported row and confirm in SQL that its journal really is gone:

```sql
SELECT journal_id FROM journals WHERE journal_id = <entity id from the report>;
-- expected: empty result set
```

- [ ] **Step 5: Confirm the prompt refuses piped input**

Run: `echo "" | php tools/settingsHealthCheck/settingsHealthCheck.php --deleted-journal --fix`
Expected: exits with `[ERROR] Refusing --fix with piped input.` and status 2, having written nothing.

- [ ] **Step 6: Confirm a declined prompt writes nothing**

Run `php tools/settingsHealthCheck/settingsHealthCheck.php --deleted-journal --fix` interactively and answer `no` at stage 1.
Expected: `Aborted: User did not confirm awareness of database deletion.` Re-run the read-only scan; the counts must be unchanged.

- [ ] **Step 7: Apply the fix**

Run `php tools/settingsHealthCheck/settingsHealthCheck.php --deleted-journal --fix` and complete all three stages.
Expected: `Failed` is absent, and `Journal rows deleted` is **greater than or equal to** the scenario-7 count from step 3. Equality is not expected: composite-key tables have no surrogate row id, so the scan reports one finding per distinct anchor value (as the rest of this tool already does) while the delete removes every underlying row. A number *lower* than the scenario count is a real problem — it means rows the scan found were not removed.

- [ ] **Step 8: Confirm convergence**

Run: `php tools/settingsHealthCheck/settingsHealthCheck.php --deleted-journal`
Expected: scenario 7 reports 0 records.

- [ ] **Step 9: Confirm no collateral damage**

Run: `php tools/settingsHealthCheck/settingsHealthCheck.php --all`
Expected: no new findings in scenarios 1-6 compared to a baseline `--all` run taken before step 7. A rise in orphaned settings would mean the cascade deleted a parent while leaving a child, i.e. a gap in the registry map.

- [ ] **Step 10: Report, do not commit**

Leave every change in the working tree and report the file list to the user.
