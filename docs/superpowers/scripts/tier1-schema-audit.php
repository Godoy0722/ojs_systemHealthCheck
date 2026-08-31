<?php

/**
 * One-off schema audit for Tier 1 PreflightCheck alignment.
 * Run: php docs/superpowers/scripts/tier1-schema-audit.php
 * Output: JSON to stdout (pipe to jq or redirect to file).
 */

require dirname(__DIR__, 4) . '/bootstrap.inc.php';
require_once dirname(__DIR__, 3) . '/src/IlluminateDatabaseGateway.php';

use APP\tools\settingsHealthCheck\src\IlluminateDatabaseGateway;
use Illuminate\Database\Capsule\Manager as Capsule;

$gw = new IlluminateDatabaseGateway();
$db = $gw->getDatabaseName();
$live = Capsule::table('journals')->pluck('journal_id')->all();

function cols(IlluminateDatabaseGateway $gw, string $table): array
{
    if (!$gw->tableExists($table)) {
        return [];
    }
    $db = $gw->getDatabaseName();
    $rows = Capsule::select(
        'SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
        [$db, $table]
    );
    $out = [];
    foreach ($rows as $row) {
        $out[] = is_object($row)
            ? ($row->column_name ?? $row->COLUMN_NAME ?? null)
            : ($row['column_name'] ?? $row['COLUMN_NAME'] ?? null);
    }
    return array_values(array_filter($out, static function ($v) {
        return $v !== null;
    }));
}

function pkCols(IlluminateDatabaseGateway $gw, string $table): array
{
    if (!$gw->tableExists($table)) {
        return [];
    }
    $db = $gw->getDatabaseName();
    $rows = Capsule::select(
        "SELECT column_name FROM information_schema.key_column_usage
         WHERE table_schema = ? AND table_name = ? AND constraint_name = 'PRIMARY'
         ORDER BY ordinal_position",
        [$db, $table]
    );
    $out = [];
    foreach ($rows as $row) {
        $out[] = is_object($row)
            ? ($row->column_name ?? $row->COLUMN_NAME ?? null)
            : ($row['column_name'] ?? $row['COLUMN_NAME'] ?? null);
    }
    return array_values(array_filter($out, static function ($v) {
        return $v !== null;
    }));
}

function deadDirect(IlluminateDatabaseGateway $gw, string $table, string $col, ?int $assocType = null)
{
    if (!$gw->tableExists($table) || !$gw->columnExists($table, $col)) {
        return null;
    }
    try {
        $q = Capsule::table($table)->whereNotIn($col, Capsule::table('journals')->select('journal_id'));
        if ($assocType !== null) {
            $q->where('assoc_type', $assocType);
        }
        return $q->count();
    } catch (Throwable $e) {
        return 'ERR:' . $e->getMessage();
    }
}

$out = [
    'database' => $db,
    'auditedAt' => date('c'),
    'liveJournalIds' => $live,
    'registryTablesAllPresent' => true,
    'candidates' => [],
    'deadJournalLeftoverCounts' => [],
    'tier2' => [],
];

$registryTables = [
    'journal_settings', 'sections', 'issues', 'custom_issue_orders', 'submission_tombstones',
    'subscription_types', 'subscriptions', 'submissions', 'user_groups', 'user_group_stage',
    'categories', 'genres', 'library_files', 'navigation_menus', 'navigation_menu_items',
    'plugin_settings', 'filters', 'metrics', 'notifications', 'email_templates', 'completed_payments',
    'announcements', 'announcement_types', 'review_forms', 'data_object_tombstone_oai_set_objects',
    'section_settings', 'issue_settings', 'issue_files', 'custom_section_orders', 'issue_galleys',
    'issue_galley_settings', 'submission_settings', 'publications', 'edit_decisions', 'review_rounds',
    'review_assignments', 'stage_assignments', 'submission_files', 'submission_comments',
    'submission_search_objects', 'publication_settings', 'publication_galleys', 'authors', 'citations',
    'publication_categories', 'publication_galley_settings', 'author_settings', 'citation_settings',
    'review_round_files', 'review_files', 'submission_file_settings', 'user_group_settings',
    'user_user_groups', 'category_settings', 'genre_settings', 'navigation_menu_item_settings',
    'navigation_menu_item_assignments', 'email_templates_settings', 'review_form_settings',
    'review_form_elements', 'review_form_element_settings', 'review_form_responses',
    'announcement_settings', 'announcement_type_settings', 'institutional_subscriptions',
    'institutional_subscription_ip', 'subscription_type_settings', 'notification_settings',
];

$missingRegistry = [];
foreach ($registryTables as $t) {
    if (!$gw->tableExists($t)) {
        $missingRegistry[] = $t;
    }
}
$out['registryTablesAllPresent'] = empty($missingRegistry);
$out['missingRegistryTables'] = $missingRegistry;

$candidateDefs = [
    ['tier' => 1, 'action' => 'add_root', 'table' => 'subeditor_submission_group', 'journalCol' => 'context_id', 'identityGuess' => ['user_id', 'user_group_id'], 'parent' => null, 'fk' => null],
    ['tier' => 1, 'action' => 'consider_root', 'table' => 'static_pages', 'journalCol' => 'context_id', 'identityGuess' => ['static_page_id'], 'parent' => null, 'fk' => null],
    ['tier' => 0, 'action' => 'exclude', 'table' => 'notification_subscription_settings', 'journalCol' => 'context', 'identityGuess' => ['setting_id'], 'parent' => null, 'fk' => null, 'note' => 'string context — deliberate exclusion'],
    ['tier' => 1, 'action' => 'add_child', 'table' => 'submission_file_revisions', 'journalCol' => null, 'identityGuess' => ['revision_id'], 'parent' => 'submission_files', 'fk' => 'submission_file_id'],
    ['tier' => 1, 'action' => 'add_child', 'table' => 'submission_search_object_keywords', 'journalCol' => null, 'identityGuess' => ['object_id', 'keyword_id'], 'parent' => 'submission_search_objects', 'fk' => 'object_id'],
    ['tier' => 1, 'action' => 'add_child', 'table' => 'filter_settings', 'journalCol' => null, 'identityGuess' => ['filter_id'], 'parent' => 'filters', 'fk' => 'filter_id'],
    ['tier' => 1, 'action' => 'add_child', 'table' => 'library_file_settings', 'journalCol' => null, 'identityGuess' => ['file_id'], 'parent' => 'library_files', 'fk' => 'file_id'],
    ['tier' => 1, 'action' => 'add_child_alt_path', 'table' => 'navigation_menu_item_assignments', 'journalCol' => null, 'identityGuess' => ['navigation_menu_item_assignment_id'], 'parent' => 'navigation_menus', 'fk' => 'navigation_menu_id', 'note' => 'already reachable via navigation_menu_items'],
    ['tier' => 1, 'action' => 'add_child', 'table' => 'review_form_responses', 'journalCol' => null, 'identityGuess' => ['review_id'], 'parent' => 'review_assignments', 'fk' => 'review_id', 'note' => 'also reachable via review_form_elements'],
    ['tier' => 1, 'action' => 'add_child', 'table' => 'queries', 'journalCol' => null, 'identityGuess' => ['query_id'], 'parent' => 'submissions', 'fk' => 'assoc_id', 'note' => 'requires assoc_type = ASSOC_TYPE_SUBMISSION'],
    ['tier' => 1, 'action' => 'add_child', 'table' => 'query_participants', 'journalCol' => null, 'identityGuess' => ['query_id', 'user_id'], 'parent' => 'queries', 'fk' => 'query_id'],
    ['tier' => 1, 'action' => 'add_tombstone_chain', 'table' => 'data_object_tombstones', 'journalCol' => null, 'identityGuess' => ['tombstone_id'], 'parent' => null, 'fk' => null, 'note' => 'no journal col; link via data_object_id → submission'],
    ['tier' => 1, 'action' => 'add_child', 'table' => 'data_object_tombstone_settings', 'journalCol' => null, 'identityGuess' => ['tombstone_id'], 'parent' => 'data_object_tombstones', 'fk' => 'tombstone_id'],
    ['tier' => 1, 'action' => 'defer', 'table' => 'files', 'journalCol' => null, 'identityGuess' => ['file_id'], 'parent' => 'submission_files', 'fk' => 'file_id', 'note' => 'shared blob table — delete only when unreferenced'],
    ['tier' => 1, 'action' => 'already_in_registry', 'table' => 'publication_categories', 'journalCol' => null, 'identityGuess' => ['publication_id'], 'parent' => 'publications', 'fk' => 'publication_id'],
    ['tier' => 2, 'action' => 'pass_g', 'table' => 'publication_settings', 'journalCol' => null, 'identityGuess' => [], 'parent' => null, 'fk' => null, 'note' => 'issueId setting-value orphan'],
];

foreach ($candidateDefs as $def) {
    $t = $def['table'];
    $c = cols($gw, $t);
    $pk = pkCols($gw, $t);
    $identity = null;
    foreach ($def['identityGuess'] as $guess) {
        if (in_array($guess, $c, true)) {
            $identity = $guess;
            break;
        }
    }
    if ($identity === null && !empty($pk)) {
        $identity = $pk[0];
    }
    $out['candidates'][] = [
        'tier' => $def['tier'],
        'action' => $def['action'],
        'table' => $t,
        'exists' => $gw->tableExists($t),
        'columns' => $c,
        'primaryKey' => $pk,
        'resolvedIdentity' => $identity,
        'journalCol' => $def['journalCol'],
        'journalColExists' => $def['journalCol'] ? $gw->columnExists($t, (string) $def['journalCol']) : null,
        'parent' => $def['parent'],
        'fk' => $def['fk'],
        'fkExists' => $def['fk'] ? $gw->columnExists($t, (string) $def['fk']) : null,
        'note' => $def['note'] ?? '',
    ];
}

// Dead-journal leftover counts for new/changed candidates
$out['deadJournalLeftoverCounts']['subeditor_submission_group'] = deadDirect($gw, 'subeditor_submission_group', 'context_id');
$out['deadJournalLeftoverCounts']['static_pages'] = deadDirect($gw, 'static_pages', 'context_id');

if ($gw->tableExists('submission_file_revisions')) {
    $out['deadJournalLeftoverCounts']['submission_file_revisions'] = Capsule::table('submission_file_revisions as r')
        ->join('submission_files as sf', 'sf.submission_file_id', '=', 'r.submission_file_id')
        ->join('submissions as s', 's.submission_id', '=', 'sf.submission_id')
        ->whereNotIn('s.context_id', $live)
        ->count();
}

if ($gw->tableExists('submission_search_object_keywords')) {
    $out['deadJournalLeftoverCounts']['submission_search_object_keywords'] = Capsule::table('submission_search_object_keywords as k')
        ->join('submission_search_objects as o', 'o.object_id', '=', 'k.object_id')
        ->join('submissions as s', 's.submission_id', '=', 'o.submission_id')
        ->whereNotIn('s.context_id', $live)
        ->count();
}

if ($gw->tableExists('filter_settings')) {
    $out['deadJournalLeftoverCounts']['filter_settings'] = Capsule::table('filter_settings as fs')
        ->join('filters as f', 'f.filter_id', '=', 'fs.filter_id')
        ->whereNotIn('f.context_id', $live)
        ->count();
}

if ($gw->tableExists('library_file_settings')) {
    $out['deadJournalLeftoverCounts']['library_file_settings'] = Capsule::table('library_file_settings as ls')
        ->join('library_files as lf', 'lf.file_id', '=', 'ls.file_id')
        ->whereNotIn('lf.context_id', $live)
        ->count();
}

if ($gw->tableExists('navigation_menu_item_assignments')) {
    $out['deadJournalLeftoverCounts']['navigation_menu_item_assignments_via_menu'] = Capsule::table('navigation_menu_item_assignments as a')
        ->join('navigation_menus as m', 'm.navigation_menu_id', '=', 'a.navigation_menu_id')
        ->whereNotIn('m.context_id', $live)
        ->count();
}

if ($gw->tableExists('review_form_responses')) {
    $out['deadJournalLeftoverCounts']['review_form_responses_via_assignment'] = Capsule::table('review_form_responses as r')
        ->join('review_assignments as ra', 'ra.review_id', '=', 'r.review_id')
        ->join('submissions as s', 's.submission_id', '=', 'ra.submission_id')
        ->whereNotIn('s.context_id', $live)
        ->count();
}

if ($gw->tableExists('queries')) {
    $out['deadJournalLeftoverCounts']['queries'] = Capsule::table('queries as q')
        ->join('submissions as s', function ($j) {
            $j->on('s.submission_id', '=', 'q.assoc_id')->where('q.assoc_type', '=', ASSOC_TYPE_SUBMISSION);
        })
        ->whereNotIn('s.context_id', $live)
        ->count();
}

if ($gw->tableExists('query_participants')) {
    $out['deadJournalLeftoverCounts']['query_participants'] = Capsule::table('query_participants as qp')
        ->join('queries as q', 'q.query_id', '=', 'qp.query_id')
        ->join('submissions as s', function ($j) {
            $j->on('s.submission_id', '=', 'q.assoc_id')->where('q.assoc_type', '=', ASSOC_TYPE_SUBMISSION);
        })
        ->whereNotIn('s.context_id', $live)
        ->count();
}

if ($gw->tableExists('data_object_tombstones')) {
    $out['deadJournalLeftoverCounts']['data_object_tombstones'] = Capsule::table('data_object_tombstones as t')
        ->join('submissions as s', 's.submission_id', '=', 't.data_object_id')
        ->whereNotIn('s.context_id', $live)
        ->count();
}

if ($gw->tableExists('data_object_tombstone_settings')) {
    $out['deadJournalLeftoverCounts']['data_object_tombstone_settings'] = Capsule::table('data_object_tombstone_settings as ts')
        ->join('data_object_tombstones as t', 't.tombstone_id', '=', 'ts.tombstone_id')
        ->join('submissions as s', 's.submission_id', '=', 't.data_object_id')
        ->whereNotIn('s.context_id', $live)
        ->count();
}

if ($gw->tableExists('files') && $gw->columnExists('submission_files', 'file_id')) {
    $out['deadJournalLeftoverCounts']['files_via_dead_submission_files'] = Capsule::table('files as f')
        ->join('submission_files as sf', 'sf.file_id', '=', 'f.file_id')
        ->join('submissions as s', 's.submission_id', '=', 'sf.submission_id')
        ->whereNotIn('s.context_id', $live)
        ->count();
}

$out['submission_file_settings_columns'] = cols($gw, 'submission_file_settings');
$out['subeditor_submission_group_columns'] = cols($gw, 'subeditor_submission_group');

if ($gw->tableExists('publication_settings')) {
    $out['tier2']['issueId_setting_orphans'] = Capsule::table('publication_settings as ps')
        ->join('publications as p', 'p.publication_id', '=', 'ps.publication_id')
        ->leftJoin('issues as i', Capsule::raw('CAST(i.issue_id AS CHAR(20))'), '=', 'ps.setting_value')
        ->where('ps.setting_name', 'issueId')
        ->whereNull('i.issue_id')
        ->count();
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
