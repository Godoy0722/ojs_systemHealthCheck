<?php

/**
 * @file tools/SettingsHealthCheck/EntityReferenceRule.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class EntityReferenceRule
 *
 * @brief One entity reference integrity rule (Pass H).
 */

namespace APP\tools\settingsHealthCheck\src;

final class EntityReferenceRule
{
    public const ACTION_DELETE_REQUIRED = 'delete_required';
    public const ACTION_NULLIFY = 'nullify';
    public const ACTION_DELETE_OPTIONAL = 'delete_optional';

    /** @var string */
    public $sourceTable;

    /** @var string */
    public $sourceColumn;

    /** @var string */
    public $referenceTable;

    /** @var string */
    public $referenceColumn;

    /** @var string */
    public $action;

    /** @var string OrphanReferenceCleaner::SCOPE_* */
    public $journalScope;

    /** @var bool */
    public $ignoreZero;

    public function __construct(
        string $sourceTable,
        string $sourceColumn,
        string $referenceTable,
        string $referenceColumn,
        string $action,
        string $journalScope = OrphanReferenceCleaner::SCOPE_NONE,
        bool $ignoreZero = false
    ) {
        $this->sourceTable = $sourceTable;
        $this->sourceColumn = $sourceColumn;
        $this->referenceTable = $referenceTable;
        $this->referenceColumn = $referenceColumn;
        $this->action = $action;
        $this->journalScope = $journalScope;
        $this->ignoreZero = $ignoreZero;
    }

    public function ruleKey(): string
    {
        return $this->sourceTable . '.' . $this->sourceColumn
            . '->' . $this->referenceTable . '.' . $this->referenceColumn;
    }
}
