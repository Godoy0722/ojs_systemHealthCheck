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
    public const REASON_DELETED_JOURNAL = 'deleted_journal';

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

    /** @var int Aggregate row count (defaults to 1). */
    public $rowCount;

    public function __construct(
        string $table,
        $pk,
        $entityId,
        string $settingName,
        ?string $locale,
        ?string $rawValue,
        string $reason,
        string $suggestedLocale,
        int $rowCount = 1
    ) {
        $this->table = $table;
        $this->pk = $pk;
        $this->entityId = $entityId;
        $this->settingName = $settingName;
        $this->locale = $locale;
        $value = (string) ($rawValue ?? '');
        $this->valuePreview = (mb_strlen($value) > self::VALUE_PREVIEW_MAX)
            ? mb_substr($value, 0, self::VALUE_PREVIEW_MAX)
            : $value;
        $this->reason = $reason;
        $this->suggestedLocale = $suggestedLocale;
        $this->rowCount = $rowCount > 0 ? $rowCount : 1;
    }

    public const BULK_PREFIX = 'bulk:';

    public static function isEntityOrphan(self $f): bool
    {
        if ($f->reason !== self::REASON_ORPHAN_ENTITY || $f->table === 'files') {
            return false;
        }
        if (strpos((string) $f->pk, '->') !== false) {
            return true;
        }
        return in_array($f->suggestedLocale, [
            EntityReferenceRule::ACTION_DELETE_REQUIRED,
            EntityReferenceRule::ACTION_NULLIFY,
            EntityReferenceRule::ACTION_DELETE_OPTIONAL,
        ], true);
    }

    /** Aggregate finding — fixed with one bulk SQL statement, not row-by-row. */
    public static function isBulk(self $f): bool
    {
        return is_string($f->pk) && strpos($f->pk, self::BULK_PREFIX) === 0;
    }

    public static function bulkPk(string $kind, string $detail = ''): string
    {
        return self::BULK_PREFIX . $kind . ($detail !== '' ? ':' . $detail : '');
    }
}
