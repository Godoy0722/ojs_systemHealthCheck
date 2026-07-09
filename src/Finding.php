<?php

/**
 * @file tools/SettingsHealthCheck/Finding.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Finding
 *
 * @brief One offending row in a *_settings table flagged by the health check.
 */

namespace APP\tools\settingsHealthCheck\src;

final class Finding
{
    public const REASON_SCHEMA_MISSING_LOCALE = 'schema_missing_locale';
    public const REASON_HEURISTIC_LOCALE_MISMATCH = 'heuristic_locale_mismatch';
    public const REASON_ORPHAN_ENTITY = 'orphan_entity';
    public const REASON_REQUIRED_NULL = 'required_null';
    public const REASON_SETTING_VALUE_NULL = 'setting_value_null';
    public const REASON_REVIEW_REVISION = 'review_revision';

    private const VALUE_PREVIEW_MAX = 80;

    /** @var string */
    public $table;
    /** @var int|string */
    public $pk;
    /** @var int|string|null */
    public $entityId;
    /** @var string */
    public $settingName;
    /** @var string|null */
    public $locale;
    /** @var string */
    public $valuePreview;
    /** @var string */
    public $reason;
    /** @var string */
    public $suggestedLocale;

    /**
     * One flagged row from a *_settings table. Raw values are truncated to
     * VALUE_PREVIEW_MAX for display.
     *
     * @param int|string $pk Primary-key value of the offending row
     * @param int|string|null $entityId Parent entity id (FK column value)
     * @param string $settingName Name of the affected setting
     * @param string|null $locale Locale tag (null or empty = missing)
     * @param string|null $rawValue Raw setting_value from DB (may be null)
     * @param string $reason One of the REASON_* constants
     * @param string $suggestedLocale Locale to fix with, or '' when not applicable
     */
    public function __construct(
        string $table,
        $pk,
        $entityId,
        string $settingName,
        ?string $locale,
        ?string $rawValue,
        string $reason,
        string $suggestedLocale
    ) {
        $this->table = $table;
        $this->pk = $pk;
        $this->entityId = $entityId;
        $this->settingName = $settingName;
        $this->locale = $locale;
        $value = (string) ($rawValue ?? '');
        $this->valuePreview = (strlen($value) > self::VALUE_PREVIEW_MAX)
            ? substr($value, 0, self::VALUE_PREVIEW_MAX)
            : $value;
        $this->reason = $reason;
        $this->suggestedLocale = $suggestedLocale;
    }
}
