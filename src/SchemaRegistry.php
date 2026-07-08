<?php

/**
 * @file tools/SettingsHealthCheck/SchemaRegistry.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SchemaRegistry
 *
 * @brief Loads pkp-lib + app entity schemas and produces a map
 *        { settingsTable => { multilingualSettingName => true } }
 *        used by the schema-driven detection pass.
 */

namespace APP\tools\settingsHealthCheck\src;

final class SchemaRegistry
{
    /**
     * Default schema-name -> settings-table mapping for OJS core entities.
     * Verified against lib/pkp/classes/migration/install/*.php and OJS schemas/.
     */
    /**
     * Entity schema name -> [main table, primary key column].
     * Used by Pass D (required-but-null detection on main entity rows).
     * Verified against pkp-lib + OJS install migrations.
     */
    public const DEFAULT_ENTITY_TABLE_MAP = [
        'announcement'      => ['announcements', 'announcement_id'],
        'author'            => ['authors', 'author_id'],
        'context'           => ['journals', 'journal_id'],
        'emailTemplate'     => ['email_templates', 'email_id'],
        'galley'            => ['publication_galleys', 'galley_id'],
        'issue'             => ['issues', 'issue_id'],
        'publication'       => ['publications', 'publication_id'],
        'section'           => ['sections', 'section_id'],
        'submission'        => ['submissions', 'submission_id'],
        'submissionFile'    => ['submission_files', 'submission_file_id'],
        'user'              => ['users', 'user_id'],
        'userGroup'         => ['user_groups', 'user_group_id'],
    ];

    public const DEFAULT_SCHEMA_TABLE_MAP = [
        'announcement' => 'announcement_settings',
        'author' => 'author_settings',
        'context' => 'journal_settings',
        'emailTemplate' => 'email_templates_settings',
        'galley' => 'publication_galley_settings',
        'issue' => 'issue_settings',
        'publication' => 'publication_settings',
        'section' => 'section_settings',
        'site' => 'site_settings',
        'submission' => 'submission_settings',
        'submissionFile' => 'submission_file_settings',
        'user' => 'user_settings',
        'userGroup' => 'user_group_settings',
    ];

    /** @var string */
    private $pkpSchemaDir;

    /** @var string */
    private $appSchemaDir;

    /** @var array<string, string> */
    private $schemaTableMap;

    /** @var array<string, array{0:string,1:string}> */
    private $entityTableMap;

    /** @var string[] */
    private array $warnings = [];

    /** @var array<string, array<string, true>> */
    private array $map = [];

    /** @var array<string, array{table:string, pk:string, requiredColumns:string[]}> */
    private array $entityMap = [];

    private bool $built = false;

    /**
     * @param array<string, string> $schemaTableMap schema name => settings table name
     * @param array<string, array{0:string,1:string}> $entityTableMap schema name => [main table, pk]
     */
    public function __construct(
        string $pkpSchemaDir,
        string $appSchemaDir,
        array $schemaTableMap = self::DEFAULT_SCHEMA_TABLE_MAP,
        array $entityTableMap = self::DEFAULT_ENTITY_TABLE_MAP
    ) {
        $this->pkpSchemaDir = $pkpSchemaDir;
        $this->appSchemaDir = $appSchemaDir;
        $this->schemaTableMap = $schemaTableMap;
        $this->entityTableMap = $entityTableMap;
    }

    /**
     * @return array<string, array<string, true>>
     */
    public function build(): array
    {
        if ($this->built) {
            return $this->map;
        }
        $this->built = true;

        $names = $this->discoverSchemaNames();
        foreach ($names as $name) {
            $props = $this->loadMergedProperties($name);
            if ($props === null) {
                continue;
            }
            $multilingual = $this->extractMultilingual($props);
            if ($multilingual) {
                if (!isset($this->schemaTableMap[$name])) {
                    $this->warnings[] = sprintf(
                        'unmapped schema: %s has multilingual properties but no settings-table mapping',
                        $name
                    );
                } else {
                    $table = $this->schemaTableMap[$name];
                    $this->map[$table] = ($this->map[$table] ?? []) + $multilingual;
                }
            }

            if (isset($this->entityTableMap[$name])) {
                $required = $this->extractRequiredColumns($props);
                if (!empty($required)) {
                    [$table, $pk] = $this->entityTableMap[$name];
                    $this->entityMap[$table] = [
                        'table' => $table,
                        'pk' => $pk,
                        'requiredColumns' => array_values($required),
                    ];
                }
            }
        }

        foreach ($this->schemaTableMap as $name => $table) {
            if (!in_array($name, $names, true)) {
                $this->warnings[] = sprintf('schema not found: %s.json', $name);
            }
        }

        return $this->map;
    }

    /**
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return string[]
     */
    public function getMappedTables(): array
    {
        return array_keys($this->map);
    }

    /**
     * @return array<string, array{table:string, pk:string, requiredColumns:string[]}>
     */
    public function buildEntities(): array
    {
        if (!$this->built) {
            $this->build();
        }
        return $this->entityMap;
    }

    /**
     * Extract names of properties marked required (validation contains "required"
     * OR a top-level 'required' array exists at schema root). Multilingual props
     * are skipped (settings-table responsibility, not main-row).
     *
     * @param array<string, array<string, mixed>> $properties
     * @return string[] DB column names (snake_case)
     */
    private function extractRequiredColumns(array $properties): array
    {
        $out = [];
        foreach ($properties as $name => $def) {
            if (!is_array($def)) {
                continue;
            }
            $multilingual = $def['multilingual'] ?? null;
            if ($multilingual === true || $multilingual === 'true' || $multilingual === 1 || $multilingual === '1') {
                continue;
            }
            $validation = $def['validation'] ?? [];
            if (!is_array($validation)) {
                continue;
            }
            $isRequired = false;
            foreach ($validation as $rule) {
                if (is_string($rule) && strcasecmp($rule, 'required') === 0) {
                    $isRequired = true;
                    break;
                }
            }
            if (!$isRequired) {
                continue;
            }
            $out[$name] = $this->camelToSnake($name);
        }
        return $out;
    }

    private function camelToSnake(string $s): string
    {
        return strtolower((string) preg_replace('/(?<!^)([A-Z])/', '_$1', $s));
    }

    /**
     * @return string[] Schema names found in either dir.
     */
    private function discoverSchemaNames(): array
    {
        $names = [];
        foreach ([$this->pkpSchemaDir, $this->appSchemaDir] as $dir) {
            foreach (glob($dir . '/*.json') ?: [] as $path) {
                $names[basename($path, '.json')] = true;
            }
        }
        return array_keys($names);
    }

    /**
     * @return array<string, array<string, mixed>>|null Merged properties (app over pkp) or null on parse failure.
     */
    private function loadMergedProperties(string $name): ?array
    {
        $pkp = $this->parseSchemaFile($this->pkpSchemaDir . '/' . $name . '.json');
        $app = $this->parseSchemaFile($this->appSchemaDir . '/' . $name . '.json');
        if ($pkp === false || $app === false) {
            return null;
        }
        $pkpProps = $pkp['properties'] ?? [];
        $appProps = $app['properties'] ?? [];
        return array_merge($pkpProps, $appProps);
    }

    /**
     * @return array<string, mixed>|false|null array on success, null if file absent, false on parse failure.
     */
    private function parseSchemaFile(string $path)
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            $this->warnings[] = sprintf('cannot read schema: %s', $path);
            return false;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->warnings[] = sprintf('invalid JSON in schema: %s', $path);
            return false;
        }
        return $decoded;
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @return array<string, true>
     */
    private function extractMultilingual(array $properties): array
    {
        $out = [];
        foreach ($properties as $name => $def) {
            if (!is_array($def)) {
                continue;
            }
            $flag = $def['multilingual'] ?? null;
            if ($flag === true || $flag === 'true' || $flag === 1 || $flag === '1') {
                $out[$name] = true;
            }
        }
        return $out;
    }
}
