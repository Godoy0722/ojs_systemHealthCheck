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

    public const VALUE_PREVIEW_MAX = 80;

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
     * @param int|string $pk
     * @param int|string|null $entityId
     */
    public function __construct(
        string $table,
        $pk,
        $entityId,
        string $settingName,
        ?string $locale,
        string $valuePreview,
        string $reason,
        string $suggestedLocale
    ) {
        $this->table = $table;
        $this->pk = $pk;
        $this->entityId = $entityId;
        $this->settingName = $settingName;
        $this->locale = $locale;
        $this->valuePreview = $valuePreview;
        $this->reason = $reason;
        $this->suggestedLocale = $suggestedLocale;
    }

    /**
     * @param int|string $pk
     * @param int|string|null $entityId
     */
    public static function fromRow(
        string $table,
        $pk,
        $entityId,
        string $settingName,
        ?string $locale,
        ?string $rawValue,
        string $reason,
        string $suggestedLocale
    ): self {
        $value = (string) ($rawValue ?? '');
        if (strlen($value) > self::VALUE_PREVIEW_MAX) {
            $value = substr($value, 0, self::VALUE_PREVIEW_MAX);
        }
        return new self(
            $table,
            $pk,
            $entityId,
            $settingName,
            $locale,
            $value,
            $reason,
            $suggestedLocale
        );
    }
}
