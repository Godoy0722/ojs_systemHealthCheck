<?php

/**
 * @file tools/settingsHealthCheck/src/SettingsFkRegistry.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SettingsFkRegistry
 *
 * @brief Explicit parent-entity FK rules for every OJS *_settings table.
 *        Used by Pass C before naming-convention guessing so irregular
 *        column/table pairs (email_id, galley_id, library file_id, etc.)
 *        resolve correctly.
 */

namespace APP\tools\settingsHealthCheck\src;

final class SettingsFkRegistry
{
    /**
     * Settings tables with no entity parent (global key/value store).
     *
     * @var string[]
     */
    public const ORPHAN_EXCLUDED = [
        'site_settings',
    ];

    /**
     * settings_table => list of FK rules.
     * Each rule: column, parentTable, parentColumn; optional ignoreZero.
     *
     * @var array<string, array<int, array{column:string,parentTable:string,parentColumn:string,ignoreZero?:bool}>>
     */
    public const RULES = [
        'announcement_settings' => [
            ['column' => 'announcement_id', 'parentTable' => 'announcements', 'parentColumn' => 'announcement_id'],
        ],
        'announcement_type_settings' => [
            ['column' => 'type_id', 'parentTable' => 'announcement_types', 'parentColumn' => 'type_id'],
        ],
        'author_settings' => [
            ['column' => 'author_id', 'parentTable' => 'authors', 'parentColumn' => 'author_id'],
        ],
        'books_for_review_settings' => [
            ['column' => 'book_id', 'parentTable' => 'books_for_review', 'parentColumn' => 'book_id'],
        ],
        'category_settings' => [
            ['column' => 'category_id', 'parentTable' => 'categories', 'parentColumn' => 'category_id'],
        ],
        'citation_settings' => [
            ['column' => 'citation_id', 'parentTable' => 'citations', 'parentColumn' => 'citation_id'],
        ],
        'controlled_vocab_entry_settings' => [
            ['column' => 'controlled_vocab_entry_id', 'parentTable' => 'controlled_vocab_entries', 'parentColumn' => 'controlled_vocab_entry_id'],
        ],
        'data_object_tombstone_settings' => [
            ['column' => 'tombstone_id', 'parentTable' => 'data_object_tombstones', 'parentColumn' => 'tombstone_id'],
        ],
        'deposit_point_settings' => [
            ['column' => 'deposit_point_id', 'parentTable' => 'deposit_points', 'parentColumn' => 'deposit_point_id'],
        ],
        'email_templates_settings' => [
            ['column' => 'email_id', 'parentTable' => 'email_templates', 'parentColumn' => 'email_id'],
        ],
        'event_log_settings' => [
            ['column' => 'log_id', 'parentTable' => 'event_log', 'parentColumn' => 'log_id'],
        ],
        'external_feed_settings' => [
            ['column' => 'feed_id', 'parentTable' => 'external_feeds', 'parentColumn' => 'feed_id'],
        ],
        'filter_settings' => [
            ['column' => 'filter_id', 'parentTable' => 'filters', 'parentColumn' => 'filter_id'],
        ],
        'genre_settings' => [
            ['column' => 'genre_id', 'parentTable' => 'genres', 'parentColumn' => 'genre_id'],
        ],
        'group_settings' => [
            ['column' => 'group_id', 'parentTable' => 'groups', 'parentColumn' => 'group_id'],
        ],
        'issue_galley_settings' => [
            ['column' => 'galley_id', 'parentTable' => 'issue_galleys', 'parentColumn' => 'galley_id'],
        ],
        'issue_settings' => [
            ['column' => 'issue_id', 'parentTable' => 'issues', 'parentColumn' => 'issue_id'],
        ],
        'journal_settings' => [
            ['column' => 'journal_id', 'parentTable' => 'journals', 'parentColumn' => 'journal_id'],
        ],
        'library_file_settings' => [
            ['column' => 'file_id', 'parentTable' => 'library_files', 'parentColumn' => 'file_id'],
        ],
        'metadata_description_settings' => [
            ['column' => 'metadata_description_id', 'parentTable' => 'metadata_descriptions', 'parentColumn' => 'metadata_description_id'],
        ],
        'navigation_menu_item_assignment_settings' => [
            ['column' => 'navigation_menu_item_assignment_id', 'parentTable' => 'navigation_menu_item_assignments', 'parentColumn' => 'navigation_menu_item_assignment_id'],
        ],
        'navigation_menu_item_settings' => [
            ['column' => 'navigation_menu_item_id', 'parentTable' => 'navigation_menu_items', 'parentColumn' => 'navigation_menu_item_id'],
        ],
        'notification_settings' => [
            ['column' => 'notification_id', 'parentTable' => 'notifications', 'parentColumn' => 'notification_id'],
        ],
        'notification_subscription_settings' => [
            ['column' => 'user_id', 'parentTable' => 'users', 'parentColumn' => 'user_id'],
            ['column' => 'context', 'parentTable' => 'journals', 'parentColumn' => 'journal_id', 'ignoreZero' => true],
        ],
        'object_for_review_settings' => [
            ['column' => 'object_id', 'parentTable' => 'object_for_review_assignments', 'parentColumn' => 'object_id'],
            ['column' => 'review_object_metadata_id', 'parentTable' => 'review_object_metadata', 'parentColumn' => 'metadata_id'],
        ],
        'plugin_settings' => [
            ['column' => 'context_id', 'parentTable' => 'journals', 'parentColumn' => 'journal_id', 'ignoreZero' => true],
        ],
        'publication_galley_settings' => [
            ['column' => 'galley_id', 'parentTable' => 'publication_galleys', 'parentColumn' => 'galley_id'],
        ],
        'publication_settings' => [
            ['column' => 'publication_id', 'parentTable' => 'publications', 'parentColumn' => 'publication_id'],
        ],
        'referral_settings' => [
            ['column' => 'referral_id', 'parentTable' => 'referrals', 'parentColumn' => 'referral_id'],
        ],
        'review_form_element_settings' => [
            ['column' => 'review_form_element_id', 'parentTable' => 'review_form_elements', 'parentColumn' => 'review_form_element_id'],
        ],
        'review_form_settings' => [
            ['column' => 'review_form_id', 'parentTable' => 'review_forms', 'parentColumn' => 'review_form_id'],
        ],
        'review_object_metadata_settings' => [
            ['column' => 'metadata_id', 'parentTable' => 'review_object_metadata', 'parentColumn' => 'metadata_id'],
        ],
        'review_object_type_settings' => [
            ['column' => 'type_id', 'parentTable' => 'review_object_types', 'parentColumn' => 'type_id'],
        ],
        'section_settings' => [
            ['column' => 'section_id', 'parentTable' => 'sections', 'parentColumn' => 'section_id'],
        ],
        'static_page_settings' => [
            ['column' => 'static_page_id', 'parentTable' => 'static_pages', 'parentColumn' => 'static_page_id'],
        ],
        'submission_file_settings' => [
            ['column' => 'submission_file_id', 'parentTable' => 'submission_files', 'parentColumn' => 'submission_file_id'],
        ],
        'submission_settings' => [
            ['column' => 'submission_id', 'parentTable' => 'submissions', 'parentColumn' => 'submission_id'],
        ],
        'subscription_type_settings' => [
            ['column' => 'type_id', 'parentTable' => 'subscription_types', 'parentColumn' => 'type_id'],
        ],
        'user_group_settings' => [
            ['column' => 'user_group_id', 'parentTable' => 'user_groups', 'parentColumn' => 'user_group_id'],
        ],
        'user_settings' => [
            ['column' => 'user_id', 'parentTable' => 'users', 'parentColumn' => 'user_id'],
        ],
    ];

    public static function isExcluded(string $settingsTable): bool
    {
        return in_array($settingsTable, self::ORPHAN_EXCLUDED, true);
    }

    /**
     * @return array<int, array{column:string,parentTable:string,parentColumn:string,ignoreZero?:bool}>
     */
    public static function rulesFor(string $settingsTable): array
    {
        return self::RULES[$settingsTable] ?? [];
    }

    /**
     * Every *_settings table the orphan pass should consider, including
     * tables without a locale column.
     *
     * @return string[]
     */
    public static function allSettingsTables(): array
    {
        return array_keys(self::RULES);
    }
}
