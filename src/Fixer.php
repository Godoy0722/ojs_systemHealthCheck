<?php

/**
 * @file tools/SettingsHealthCheck/Fixer.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Fixer
 *
 * @brief Applies the basic remediations for the findings the Scanner produced.
 *        WRITES to the database — only invoked when --fix is passed.
 *
 *        - Orphaned settings      -> the dangling row is deleted.
 *        - Missing-locale settings -> the row is stamped with the default locale.
 *        - Empty-field findings    -> left untouched (no safe automatic fix yet).
 *
 *        Each row is fixed independently; a failure on one row is recorded as a
 *        warning and does not abort the rest of the run.
 */

namespace APP\tools\settingsHealthCheck\src;

final class Fixer
{
    /** @var DatabaseGateway */
    private $gateway;

    /** @var string Locale stamped onto missing-locale rows that carry no suggestion. */
    private string $defaultLocale;

    /** @var string[] */
    private array $warnings = [];

    public function __construct(DatabaseGateway $gateway)
    {
        $this->gateway = $gateway;
        $locale = $gateway->getSitePrimaryLocale();
        $this->defaultLocale = $locale !== '' ? $locale : 'en';
    }

    /**
     * @param Finding[] $findings
     * @return array{orphansDeleted:int, localesFixed:int, skipped:int, failed:int}
     */
    public function fix(array $findings): array
    {
        $result = [
            'orphansDeleted' => 0,
            'localesFixed' => 0,
            'reviewFilesDeleted' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($findings as $finding) {
            try {
                switch ($finding->reason) {
                    case Finding::REASON_ORPHAN_ENTITY:
                        $deleted = $this->gateway->deleteSettingRow(
                            $finding->table,
                            $finding->pk,
                            $finding->settingName,
                            $finding->locale
                        );
                        $deleted > 0 ? $result['orphansDeleted'] += $deleted : $result['failed']++;
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
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
