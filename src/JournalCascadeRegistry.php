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
     *
     * When the preferred identity column is absent from the live schema
     * (e.g. metrics.metric_id in OJS 3.3), build() falls back to the journal
     * column and marks the step aggregate so Pass F counts rows instead of
     * emitting one finding per distinct identity value.
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
        'announcements'                         => 'announcement_id',
        'announcement_types'                    => 'type_id',
        'review_forms'                          => 'review_form_id',
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
     *   aggregate — true when identity equals the journal column (no row-level PK)
     *
     * Tables or columns absent from the live schema are skipped with a
     * warning; their children are skipped too, since they are unreachable.
     * Idempotent — subsequent calls return the cached plan.
     *
     * @return array<int, array{table:string, identity:string, source:string, column:string, parent:?string, assocType:?int, depth:int, via:string, aggregate:bool}>
     */
    public function build(): array
    {
        if ($this->built) {
            return $this->plan;
        }
        $this->built = true;

        $seen = [];

        foreach ($this->directRoots as $table => $cols) {
            [$journalColumn, $preferredIdentity] = $cols;
            if (!$this->verifyJournalRoot($table, $journalColumn)) {
                continue;
            }
            $identity = $this->resolveIdentityColumn($table, $journalColumn, $preferredIdentity);
            if ($identity === null) {
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
                'aggregate' => $identity === $journalColumn,
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
                'aggregate' => false,
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
                'aggregate' => false,
            ];
            $seen[$childTable] = true;
            $this->appendChildren($childTable, $via . ' > ' . $childTable, $depth + 1, $seen);
        }
    }

    /**
     * Confirms a direct journal root table and its journal column exist.
     *
     * @param string $table
     * @param string $journalColumn
     * @return bool True when the table and journal column are present
     */
    private function verifyJournalRoot(string $table, string $journalColumn): bool
    {
        if (!$this->gateway->tableExists($table)) {
            $this->warnings[] = sprintf('cascade: table %s not present in this database, skipped', $table);
            return false;
        }
        if (!$this->gateway->columnExists($table, $journalColumn)) {
            $this->warnings[] = sprintf('cascade: %s has no column %s, skipped', $table, $journalColumn);
            return false;
        }
        return true;
    }

    /**
     * Picks the row-identity column for a direct root. Uses the preferred
     * surrogate key when present (OJS 3.5+ metrics.metric_id); otherwise
     * falls back to the journal column for composite OJS 3.3 tables.
     *
     * @param string $table
     * @param string $journalColumn
     * @param string $preferredIdentity
     * @return string|null Resolved identity column, or null when unresolvable
     */
    private function resolveIdentityColumn(string $table, string $journalColumn, string $preferredIdentity): ?string
    {
        if ($this->gateway->columnExists($table, $preferredIdentity)) {
            return $preferredIdentity;
        }
        if ($preferredIdentity !== $journalColumn && $this->gateway->columnExists($table, $journalColumn)) {
            return $journalColumn;
        }
        $this->warnings[] = sprintf('cascade: %s has no column %s, skipped', $table, $preferredIdentity);
        return null;
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
