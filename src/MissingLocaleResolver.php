<?php

/**
 * @file tools/SettingsHealthCheck/MissingLocaleResolver.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class MissingLocaleResolver
 *
 * @brief Resolves empty/NULL-locale settings rows one at a time against the
 *        locales of the journal that owns them. For each row, the locales
 *        already stored for the same field are re-read from the database:
 *          - the journal locales not yet present are candidates; the row is
 *            retagged with the journal primary locale when it is a candidate,
 *            otherwise with the first remaining candidate;
 *          - when every journal locale is already present, the row is a
 *            duplicate and is deleted.
 *        Rows outside any live journal use the site locales instead.
 */

namespace APP\tools\settingsHealthCheck\src;

final class MissingLocaleResolver
{
    private const PAGE_SIZE = 500;

    /** Bound on cached (table, parent id) => journal id lookups. */
    private const JOURNAL_CACHE_MAX = 20000;

    /** @var IlluminateDatabaseGateway */
    private $gateway;

    /** @var JournalCascadeRegistry|null */
    private $cascadeRegistry;

    /** @var array<int, string[]>|null */
    private $journalLocales = null;

    /** @var string[]|null */
    private $siteLocales = null;

    /** @var array<string, array<string, mixed>>|null */
    private $planByTable = null;

    /** @var array<string, int|null> */
    private array $journalIdCache = [];

    /** @var string[] */
    private array $warnings = [];

    public function __construct(IlluminateDatabaseGateway $gateway, ?JournalCascadeRegistry $cascadeRegistry = null)
    {
        $this->gateway = $gateway;
        $this->cascadeRegistry = $cascadeRegistry;
    }

    /**
     * @param string[] $settingNames
     * @param mixed $anchorValue Restricts the pass to one entity (table anchor column), null for the whole table
     * @return array{retagged:int, deleted:int, failed:int}
     */
    public function resolve(string $table, array $settingNames, $anchorValue = null): array
    {
        $result = ['retagged' => 0, 'deleted' => 0, 'failed' => 0];
        if (empty($settingNames) || !$this->gateway->tableExists($table)) {
            return $result;
        }

        $groupColumns = $this->gateway->getLocaleGroupColumns($table);
        $filters = [];
        if ($anchorValue !== null) {
            $anchor = $this->gateway->getTableMetaPublic($table)['pk'];
            if ($anchor === null) {
                return $result;
            }
            $filters[$anchor] = $anchorValue;
        }
        $step = $this->getPlanByTable()[$table] ?? null;
        $parentColumn = $step !== null && in_array($step['column'], $groupColumns, true) ? $step['column'] : null;

        // Resolved rows leave the empty-locale set, so only unresolved rows shift the page.
        $offset = 0;
        while (true) {
            $rows = $this->gateway->findEmptyLocaleRowsPage(
                $table,
                $groupColumns,
                $settingNames,
                $filters,
                $offset,
                self::PAGE_SIZE
            );
            if (empty($rows)) {
                break;
            }
            foreach ($rows as $row) {
                try {
                    $outcome = $this->resolveRow($table, $row, $step, $parentColumn);
                } catch (\Throwable $e) {
                    $outcome = null;
                    $this->warnings[] = sprintf(
                        'Locale fix failed for %s (%s): %s',
                        $table,
                        self::describeGroup($row['group']),
                        $e->getMessage()
                    );
                }
                if ($outcome === null) {
                    $result['failed']++;
                    $offset++;
                    continue;
                }
                $result[$outcome]++;
            }
        }
        return $result;
    }

    /**
     * @param array{group: array<string, mixed>, locale: ?string} $row
     * @param array<string, mixed>|null $step
     * @return string|null 'retagged', 'deleted', or null when nothing changed
     */
    private function resolveRow(string $table, array $row, ?array $step, ?string $parentColumn): ?string
    {
        $locales = $this->localesForRow($table, $row['group'], $step, $parentColumn);
        $tagged = $this->gateway->getTaggedLocales($table, $row['group']);
        $missing = array_values(array_diff($locales, $tagged));

        if (empty($missing)) {
            return $this->gateway->deleteEmptyLocaleRow($table, $row['group'], $row['locale']) > 0
                ? 'deleted'
                : null;
        }
        return $this->gateway->retagEmptyLocaleRow($table, $row['group'], $row['locale'], $missing[0]) > 0
            ? 'retagged'
            : null;
    }

    /**
     * @param array<string, mixed> $group
     * @param array<string, mixed>|null $step
     * @return string[] Primary locale first
     */
    private function localesForRow(string $table, array $group, ?array $step, ?string $parentColumn): array
    {
        $journalId = null;
        if ($step !== null && $parentColumn !== null) {
            $journalId = $this->resolveJournalId($table, $step, $group[$parentColumn]);
        }
        $journalLocales = $this->getJournalLocales();
        if ($journalId !== null && !empty($journalLocales[$journalId])) {
            return $journalLocales[$journalId];
        }
        return $this->getSiteLocales();
    }

    /**
     * @param array<string, mixed> $step
     * @param mixed $parentValue
     */
    private function resolveJournalId(string $table, array $step, $parentValue): ?int
    {
        if ($parentValue === null) {
            return null;
        }
        $key = $table . "\0" . (string) $parentValue;
        if (!array_key_exists($key, $this->journalIdCache)) {
            if (count($this->journalIdCache) >= self::JOURNAL_CACHE_MAX) {
                $this->journalIdCache = [];
            }
            $this->journalIdCache[$key] = $this->gateway->resolveJournalIdForSettingsRow(
                $step,
                $this->getPlanByTable(),
                $parentValue
            );
        }
        return $this->journalIdCache[$key];
    }

    /** @return array<string, array<string, mixed>> */
    private function getPlanByTable(): array
    {
        if ($this->planByTable === null) {
            $this->planByTable = [];
            if ($this->cascadeRegistry !== null) {
                try {
                    foreach ($this->cascadeRegistry->build() as $step) {
                        $this->planByTable[$step['table']] = $step;
                    }
                } catch (\Throwable $e) {
                    $this->warnings[] = 'Journal paths unavailable, falling back to site locales: ' . $e->getMessage();
                }
            }
        }
        return $this->planByTable;
    }

    /** @return array<int, string[]> */
    private function getJournalLocales(): array
    {
        if ($this->journalLocales === null) {
            $this->journalLocales = $this->gateway->getJournalLocales();
        }
        return $this->journalLocales;
    }

    /** @return string[] */
    private function getSiteLocales(): array
    {
        if ($this->siteLocales === null) {
            $this->siteLocales = $this->gateway->getSiteLocales();
        }
        return $this->siteLocales;
    }

    /** @param array<string, mixed> $group */
    private static function describeGroup(array $group): string
    {
        $parts = [];
        foreach ($group as $column => $value) {
            $parts[] = $column . '=' . ($value === null ? 'NULL' : (string) $value);
        }
        return implode(', ', $parts);
    }

    /** @return string[] */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
