<?php

/**
 * @file tools/SettingsHealthCheck/EntityReferenceRegistry.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class EntityReferenceRegistry
 *
 * @brief Entity reference rules for Pass H (excludes *_settings and Pass F/C overlap).
 */

namespace APP\tools\settingsHealthCheck\src;

final class EntityReferenceRegistry
{
    /** @var array<string, EntityReferenceRule>|null */
    private static $byKey;

    /** @return EntityReferenceRule[] */
    public static function rules(): array
    {
        if (self::$byKey !== null) {
            return array_values(self::$byKey);
        }

        $s = OrphanReferenceCleaner::SCOPE_NONE;
        $ctx = OrphanReferenceCleaner::SCOPE_CONTEXT_ID;
        $jour = OrphanReferenceCleaner::SCOPE_JOURNAL_ID;
        $sub = OrphanReferenceCleaner::SCOPE_SUBMISSION;
        $pub = OrphanReferenceCleaner::SCOPE_PUBLICATION;
        $iss = OrphanReferenceCleaner::SCOPE_ISSUE;
        $sf = OrphanReferenceCleaner::SCOPE_SUBMISSION_FILE;
        $rev = OrphanReferenceCleaner::SCOPE_REVIEW;
        $qry = OrphanReferenceCleaner::SCOPE_QUERY;
        $tomb = OrphanReferenceCleaner::SCOPE_TOMBSTONE;
        $subsc = OrphanReferenceCleaner::SCOPE_SUBSCRIPTION;
        $nav = OrphanReferenceCleaner::SCOPE_NAVIGATION_MENU;
        $sec = OrphanReferenceCleaner::SCOPE_SECTION;

        $req = EntityReferenceRule::ACTION_DELETE_REQUIRED;
        $nil = EntityReferenceRule::ACTION_NULLIFY;
        $del = EntityReferenceRule::ACTION_DELETE_OPTIONAL;

        $rules = [
            new EntityReferenceRule('submissions', 'current_publication_id', 'publications', 'publication_id', $req, $ctx),
            new EntityReferenceRule('submission_files', 'submission_id', 'submissions', 'submission_id', $req, $sub),
            new EntityReferenceRule('submission_files', 'file_id', 'files', 'file_id', $req, $sub),
            new EntityReferenceRule('submission_files', 'uploader_user_id', 'users', 'user_id', $nil, $sub),
            new EntityReferenceRule('submission_files', 'source_submission_file_id', 'submission_files', 'submission_file_id', $nil, $sub),
            new EntityReferenceRule('submission_files', 'genre_id', 'genres', 'genre_id', $nil, $sub),
            new EntityReferenceRule('publications', 'submission_id', 'submissions', 'submission_id', $req, $sub),
            new EntityReferenceRule('publications', 'primary_contact_id', 'authors', 'author_id', $nil, $pub),
            new EntityReferenceRule('categories', 'parent_id', 'categories', 'category_id', $del, $ctx, true),
            new EntityReferenceRule('review_rounds', 'submission_id', 'submissions', 'submission_id', $req, $sub),
            new EntityReferenceRule('authors', 'publication_id', 'publications', 'publication_id', $req, $pub),
            new EntityReferenceRule('controlled_vocab_entries', 'controlled_vocab_id', 'controlled_vocabs', 'controlled_vocab_id', $req, $s),
            new EntityReferenceRule('filters', 'filter_group_id', 'filter_groups', 'filter_group_id', $req, $s),
            new EntityReferenceRule('navigation_menu_item_assignments', 'navigation_menu_item_id', 'navigation_menu_items', 'navigation_menu_item_id', $req, $nav),
            new EntityReferenceRule('navigation_menu_item_assignments', 'navigation_menu_id', 'navigation_menus', 'navigation_menu_id', $req, $nav),
            new EntityReferenceRule('review_assignments', 'submission_id', 'submissions', 'submission_id', $req, $sub),
            new EntityReferenceRule('review_assignments', 'review_round_id', 'review_rounds', 'review_round_id', $req, $rev),
            new EntityReferenceRule('review_assignments', 'reviewer_id', 'users', 'user_id', $req, $rev),
            new EntityReferenceRule('review_assignments', 'review_form_id', 'review_forms', 'review_form_id', $nil, $rev),
            new EntityReferenceRule('review_form_elements', 'review_form_id', 'review_forms', 'review_form_id', $req, $s),
            new EntityReferenceRule('announcements', 'type_id', 'announcement_types', 'type_id', $nil, $s),
            new EntityReferenceRule('citations', 'publication_id', 'publications', 'publication_id', $req, $pub),
            new EntityReferenceRule('event_log', 'user_id', 'users', 'user_id', $del, $s),
            new EntityReferenceRule('library_files', 'submission_id', 'submissions', 'submission_id', $del, $ctx, true),
            new EntityReferenceRule('notifications', 'user_id', 'users', 'user_id', $del, $s, true),
            new EntityReferenceRule('submission_search_objects', 'submission_id', 'submissions', 'submission_id', $req, $sub),
            new EntityReferenceRule('access_keys', 'user_id', 'users', 'user_id', $req, $s),
            new EntityReferenceRule('edit_decisions', 'submission_id', 'submissions', 'submission_id', $req, $sub),
            new EntityReferenceRule('edit_decisions', 'editor_id', 'users', 'user_id', $req, $sub),
            new EntityReferenceRule('edit_decisions', 'review_round_id', 'review_rounds', 'review_round_id', $del, $sub, true),
            new EntityReferenceRule('email_log_users', 'user_id', 'users', 'user_id', $req, $s),
            new EntityReferenceRule('email_log_users', 'email_log_id', 'email_log', 'log_id', $req, $s),
            new EntityReferenceRule('data_object_tombstone_oai_set_objects', 'tombstone_id', 'data_object_tombstones', 'tombstone_id', $req, $tomb),
            new EntityReferenceRule('data_object_tombstone_settings', 'tombstone_id', 'data_object_tombstones', 'tombstone_id', $req, $tomb),
            new EntityReferenceRule('publication_categories', 'publication_id', 'publications', 'publication_id', $req, $pub),
            new EntityReferenceRule('publication_categories', 'category_id', 'categories', 'category_id', $req, $pub),
            new EntityReferenceRule('query_participants', 'user_id', 'users', 'user_id', $req, $qry),
            new EntityReferenceRule('query_participants', 'query_id', 'queries', 'query_id', $req, $qry),
            new EntityReferenceRule('review_files', 'submission_file_id', 'submission_files', 'submission_file_id', $req, $rev),
            new EntityReferenceRule('review_files', 'review_id', 'review_assignments', 'review_id', $req, $rev),
            new EntityReferenceRule('review_form_responses', 'review_id', 'review_assignments', 'review_id', $req, $rev),
            new EntityReferenceRule('review_form_responses', 'review_form_element_id', 'review_form_elements', 'review_form_element_id', $req, $rev),
            new EntityReferenceRule('review_round_files', 'submission_id', 'submissions', 'submission_id', $req, $sub),
            new EntityReferenceRule('review_round_files', 'submission_file_id', 'submission_files', 'submission_file_id', $req, $sub),
            new EntityReferenceRule('review_round_files', 'review_round_id', 'review_rounds', 'review_round_id', $req, $sub),
            new EntityReferenceRule('sessions', 'user_id', 'users', 'user_id', $del, $s),
            new EntityReferenceRule('stage_assignments', 'user_id', 'users', 'user_id', $req, $sub),
            new EntityReferenceRule('stage_assignments', 'user_group_id', 'user_groups', 'user_group_id', $req, $sub),
            new EntityReferenceRule('stage_assignments', 'submission_id', 'submissions', 'submission_id', $req, $sub),
            new EntityReferenceRule('subeditor_submission_group', 'user_id', 'users', 'user_id', $req, $ctx),
            new EntityReferenceRule('submission_comments', 'submission_id', 'submissions', 'submission_id', $req, $sub),
            new EntityReferenceRule('submission_comments', 'author_id', 'users', 'user_id', $req, $sub),
            new EntityReferenceRule('submission_file_revisions', 'submission_file_id', 'submission_files', 'submission_file_id', $req, $sf),
            new EntityReferenceRule('submission_file_revisions', 'file_id', 'files', 'file_id', $req, $sf),
            new EntityReferenceRule('submission_search_object_keywords', 'object_id', 'submission_search_objects', 'object_id', $req, $s),
            new EntityReferenceRule('submission_search_object_keywords', 'keyword_id', 'submission_search_keyword_list', 'keyword_id', $req, $s),
            new EntityReferenceRule('temporary_files', 'user_id', 'users', 'user_id', $req, $s),
            new EntityReferenceRule('user_group_stage', 'user_group_id', 'user_groups', 'user_group_id', $req, $ctx),
            new EntityReferenceRule('user_interests', 'user_id', 'users', 'user_id', $req, $s),
            new EntityReferenceRule('user_interests', 'controlled_vocab_entry_id', 'controlled_vocab_entries', 'controlled_vocab_entry_id', $req, $s),
            new EntityReferenceRule('user_user_groups', 'user_id', 'users', 'user_id', $req, $s),
            new EntityReferenceRule('user_user_groups', 'user_group_id', 'user_groups', 'user_group_id', $req, $s),
            new EntityReferenceRule('publications', 'section_id', 'sections', 'section_id', $del, $pub),
            new EntityReferenceRule('publication_galleys', 'publication_id', 'publications', 'publication_id', $req, $pub),
            new EntityReferenceRule('publication_galleys', 'submission_file_id', 'submission_files', 'submission_file_id', $del, $pub),
            new EntityReferenceRule('issue_galleys', 'issue_id', 'issues', 'issue_id', $req, $iss),
            new EntityReferenceRule('issue_galleys', 'file_id', 'issue_files', 'file_id', $req, $iss),
            new EntityReferenceRule('sections', 'review_form_id', 'review_forms', 'review_form_id', $nil, $jour),
            new EntityReferenceRule('issue_files', 'issue_id', 'issues', 'issue_id', $req, $iss),
            new EntityReferenceRule('subscriptions', 'user_id', 'users', 'user_id', $req, $subsc),
            new EntityReferenceRule('subscriptions', 'type_id', 'subscription_types', 'type_id', $req, $subsc),
            new EntityReferenceRule('completed_payments', 'user_id', 'users', 'user_id', $del, $s),
            new EntityReferenceRule('custom_issue_orders', 'issue_id', 'issues', 'issue_id', $req, $iss),
            new EntityReferenceRule('custom_section_orders', 'section_id', 'sections', 'section_id', $req, $sec),
            new EntityReferenceRule('custom_section_orders', 'issue_id', 'issues', 'issue_id', $req, $iss),
            new EntityReferenceRule('institutional_subscriptions', 'subscription_id', 'subscriptions', 'subscription_id', $req, $subsc),
        ];

        self::$byKey = [];
        foreach ($rules as $rule) {
            self::$byKey[$rule->ruleKey()] = $rule;
        }

        return array_values(self::$byKey);
    }

    public static function findByKey(string $key): ?EntityReferenceRule
    {
        self::rules();
        return self::$byKey[$key] ?? null;
    }
}
