<?php

/**
 * @file tools/SettingsHealthCheck/AssocLeftoverRegistry.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AssocLeftoverRegistry
 *
 * @brief Polymorphic assoc_type/assoc_id leftovers for Pass H.
 *
 *          A dangling user_id/sender_id on these tables is history of a live
 *          object and must not be deleted. A row whose assoc parent is gone is
 *          leftover of a deleted object and can be deleted. Unknown assoc_type
 *          values are left untouched.
 */

namespace APP\tools\settingsHealthCheck\src;

final class AssocLeftoverRule
{
    /** @var string */
    public $sourceTable;

    /** @var int[] */
    public $assocTypes;

    /** @var array<int, array{0:string,1:string}> Child table, FK column. Deleted before the parent. */
    public $dependents;

    /**
     * @param int[] $assocTypes
     * @param array<int, array{0:string,1:string}> $dependents
     */
    public function __construct(string $sourceTable, array $assocTypes, array $dependents = [])
    {
        $this->sourceTable = $sourceTable;
        $this->assocTypes = $assocTypes;
        $this->dependents = $dependents;
    }

    public function ruleKey(): string
    {
        return $this->sourceTable . '.assoc_id->assoc_parent';
    }
}

final class AssocLeftoverRegistry
{
    /** @var array<string, AssocLeftoverRule>|null */
    private static $byKey;

    /**
     * assoc_type => [parent table, parent PK]. Only mapped types are eligible
     * for leftover deletion; anything else is kept.
     *
     * @return array<int, array{0:string,1:string}>
     */
    public static function parentMap(): array
    {
        return [
            ASSOC_TYPE_SUBMISSION => ['submissions', 'submission_id'],
            ASSOC_TYPE_SUBMISSION_FILE => ['submission_files', 'submission_file_id'],
            ASSOC_TYPE_QUERY => ['queries', 'query_id'],
            ASSOC_TYPE_REVIEW_RESPONSE => ['review_assignments', 'review_id'],
            ASSOC_TYPE_NOTE => ['notes', 'note_id'],
            ASSOC_TYPE_REVIEW_ASSIGNMENT => ['review_assignments', 'review_id'],
            ASSOC_TYPE_ANNOUNCEMENT => ['announcements', 'announcement_id'],
            ASSOC_TYPE_QUEUED_PAYMENT => ['queued_payments', 'queued_payment_id'],
            ASSOC_TYPE_REVIEW_ROUND => ['review_rounds', 'review_round_id'],
            ASSOC_TYPE_REPRESENTATION => ['publication_galleys', 'galley_id'],
            ASSOC_TYPE_JOURNAL => ['journals', 'journal_id'],
            ASSOC_TYPE_ISSUE => ['issues', 'issue_id'],
            ASSOC_TYPE_ISSUE_GALLEY => ['issue_galleys', 'galley_id'],
            ASSOC_TYPE_PUBLICATION => ['publications', 'publication_id'],
        ];
    }

    /** @return AssocLeftoverRule[] */
    public static function rules(): array
    {
        if (self::$byKey !== null) {
            return array_values(self::$byKey);
        }

        $rules = [
            new AssocLeftoverRule('event_log', [
                ASSOC_TYPE_SUBMISSION,
                ASSOC_TYPE_SUBMISSION_FILE,
            ], [
                ['event_log_settings', 'log_id'],
            ]),
            new AssocLeftoverRule('email_log', [
                ASSOC_TYPE_SUBMISSION,
            ], [
                ['email_log_users', 'email_log_id'],
            ]),
            new AssocLeftoverRule('notes', [
                ASSOC_TYPE_QUERY,
            ]),
            new AssocLeftoverRule('item_views', [
                ASSOC_TYPE_REVIEW_RESPONSE,
                ASSOC_TYPE_NOTE,
            ]),
            new AssocLeftoverRule('notifications', [
                ASSOC_TYPE_SUBMISSION,
                ASSOC_TYPE_QUERY,
                ASSOC_TYPE_REVIEW_ASSIGNMENT,
                ASSOC_TYPE_ANNOUNCEMENT,
                ASSOC_TYPE_QUEUED_PAYMENT,
                ASSOC_TYPE_REVIEW_ROUND,
                ASSOC_TYPE_REPRESENTATION,
            ], [
                ['notification_settings', 'notification_id'],
            ]),
        ];

        self::$byKey = [];
        foreach ($rules as $rule) {
            self::$byKey[$rule->ruleKey()] = $rule;
        }

        return array_values(self::$byKey);
    }

    public static function findByKey(string $key): ?AssocLeftoverRule
    {
        self::rules();
        return self::$byKey[$key] ?? null;
    }
}
