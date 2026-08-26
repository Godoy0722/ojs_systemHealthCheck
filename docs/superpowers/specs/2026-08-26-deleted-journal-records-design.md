# Deleted-Journal Leftovers — Design

Date: 2026-08-26
Tool: `tools/settingsHealthCheck` (OJS 3.3 / PHP 7.4)
Status: approved, pending implementation plan

## Problem

`JournalDAO` does not override `deleteById`. It extends `ContextDAO` → `SchemaDAO`, and
`SchemaDAO::deleteById` (`lib/pkp/classes/db/SchemaDAO.inc.php`) performs exactly two statements:

```
DELETE FROM journals         WHERE journal_id = ?
DELETE FROM journal_settings WHERE journal_id = ?
```

Every other journal-scoped table is left untouched. OJS 3.3 schemas declare plain indexes, not
foreign-key constraints, so the database does not cascade either. The result: **any journal ever
deleted through OJS leaves residue** — sections, issues, submissions and their entire descendant
trees, subscriptions, plugin settings, metrics, notifications, and more.

The existing Pass C (orphaned settings) catches only `*_settings` tables whose FK dangles. It cannot
see main entity tables, and it cannot see polymorphic `assoc_type`/`assoc_id` references. This
scenario closes that gap.

## Scope

Full cascade. Detection and deletion cover three reference styles:

1. **Direct FK columns** — `journal_id` or `context_id` pointing at `journals.journal_id`.
2. **Polymorphic references** — `assoc_type = 256` (`ASSOC_TYPE_JOURNAL`, `0x0000100`, defined in
   `classes/core/Application.inc.php`) with `assoc_id` = journal id.
3. **Descendant chains** — tables with no journal column that become orphaned when their
   journal-scoped parent dies.

Deleting only the top level would strand the descendants, creating a new generation of orphans, so
the cascade is required for the fix to leave the database consistent.

### Deliberately excluded

`notification_subscription_settings.context` and `access_keys.context` carry a *string* context
value, not a journal FK. Probing them by name would match and delete unrelated rows.

## Architecture

The feature adds no new architectural concepts. It slots into the tool's existing five-pass shape.

### Pass F — `Scanner::CHECK_JOURNAL`

Runs after Pass E, structured like `runReviewPass()`:

1. Ask the gateway for the dead-journal id set.
2. Walk the cascade registry children-first, emitting one `Finding` per row.
3. Record per-table results in `tableResults` using the existing shape (`kind`, `settingsChecked`,
   `findingsCount`, `status`, `note`, `orphanCount`, `orphanFk`, `orphanStatus`) so
   `finalizeTableResults()` and the report need no special-casing.

`Finding` fields for this reason code:

| Field | Value |
|---|---|
| `table` | the table holding the leftover row |
| `pk` | that row's own primary key |
| `entityId` | the dead journal id |
| `settingName` | the journal reference column (`journal_id`, `context_id`, or `assoc_id`) |
| `locale` | `null` |
| `valuePreview` | the chain that reached this row, as `root_table > child_table` (roots use their own name alone) |
| `reason` | `REASON_DELETED_JOURNAL` |
| `suggestedLocale` | `''` |

### New class — `JournalCascadeRegistry`

Lives in `src/`, sibling of `SchemaRegistry`, following the same conventions: OJS file-doc header,
`final class`, typed private properties, `build()` idempotent behind a `$built` guard,
`getWarnings(): string[]` for anything it could not resolve.

Holds two public constants so the map is reviewable in one place, plus information_schema
auto-discovery to catch plugin tables the constants do not list:

- `DEFAULT_JOURNAL_COLUMNS` — `['journal_id', 'context_id']`.
- `DEFAULT_CASCADE_MAP` — ordered roots and their descendant chains.

### Gateway additions

Three methods on `IlluminateDatabaseGateway`, all `Capsule`-based, `tableExists()`-guarded,
`cursor()` for reads, `\Throwable` swallowed to a safe empty result like every existing method:

- `findDeadJournalIds(array $tables): array`
- `findJournalScopedRows(string $table, string $column, array $deadIds): iterable`
- `deleteJournalCascade(int $journalId, array $orderedPlan): int`

`deleteJournalCascade()` issues the deletes only; it does **not** open a transaction. The `Fixer`
owns transaction scope so that one rollback boundary covers exactly one journal.

### Fixer

New `REASON_DELETED_JOURNAL` case. Findings are grouped by journal id; each journal is deleted
inside `Capsule::connection()->transaction()`, children-first. A failure rolls back that journal
only, is recorded via `$this->warnings[]`, and the remaining journals still proceed.

Result array gains `journalRecordsDeleted` and `alreadyRemoved`, and
`SettingsHealthCheckTool::renderFixSummary()` gains a line for each — `journalRecordsDeleted` in
green alongside the other deletion counters, `alreadyRemoved` dimmed, printed only when non-zero so
the summary does not grow for runs that never touched this scenario.

### Overlap with Pass C

Both passes legitimately see `journal_settings` rows for a dead journal. The report lists both
scenarios honestly rather than hiding one. In `--fix`, the deleted-journal group is processed
**before** the orphan group; an orphan row that is already gone increments `alreadyRemoved`, not
`failed`, so the summary stays truthful.

## Cascade registry contents

Delete order is children → parents, mirroring `IssueDAO::deleteObject` (the in-repo precedent for
manual cascade in OJS 3.3).

A table appears in exactly one place in the registry. `user_group_stage` and `submission_tombstones`
each carry their own journal column, so both are roots rather than descendants of `user_groups` and
`submissions` — this keeps them reachable even when their nominal parent row is already gone, and
guarantees no table is visited twice in one cascade.

### Roots — direct `journal_id`

`journal_settings`, `sections`, `issues`, `custom_issue_orders`, `submission_tombstones`,
`subscription_types`, `subscriptions`

### Roots — direct `context_id`

`submissions`, `user_groups`, `user_group_stage`, `categories`, `genres`, `library_files`,
`navigation_menus`, `navigation_menu_items`, `plugin_settings`, `filters`, `metrics`,
`notifications`, `email_templates`, `completed_payments`

### Roots — polymorphic, `assoc_type = 256`

`announcements`, `announcement_types`, `review_forms`, `data_object_tombstone_oai_set_objects`

### Descendant chains

| Parent | Children (delete before parent) |
|---|---|
| `sections` | `section_settings` |
| `issues` | `issue_settings`, `issue_galleys` → `issue_galley_settings`, `issue_files`, `custom_section_orders` |
| `submissions` | `submission_settings`, `publications` → `publication_settings` + `publication_galleys` → `publication_galley_settings`, `authors` → `author_settings`, `citations` → `citation_settings`, `edit_decisions`, `review_rounds` → `review_round_files`, `review_assignments` → `review_files`, `stage_assignments`, `submission_files` → `submission_file_settings`, `submission_comments`, `submission_search_objects` |
| `user_groups` | `user_group_settings`, `user_user_groups` |
| `categories` | `category_settings`, `publication_categories` |
| `genres` | `genre_settings` |
| `navigation_menu_items` | `navigation_menu_item_settings`, `navigation_menu_item_assignments` |
| `email_templates` | `email_templates_settings` |
| `review_forms` | `review_form_settings`, `review_form_elements` → `review_form_element_settings`, `review_form_responses` |
| `announcements` | `announcement_settings` |
| `announcement_types` | `announcement_type_settings` |
| `subscriptions` | `institutional_subscriptions` → `institutional_subscription_ip` |
| `subscription_types` | `subscription_type_settings` |
| `notifications` | `notification_settings` |

## Data flow

Detection issues one `SELECT DISTINCT <column>` per direct root table, left-joined against
`journals`, unioned into a dead-id set. Descendant rows resolve by joining up their chain to a dead
root. The dead journal id is the only key that crosses table boundaries, which keeps each query
independent and bounded.

## Naming

Follows the conventions already established by the review-revision scenario:

| Layer | New name |
|---|---|
| `Finding` | `REASON_DELETED_JOURNAL = 'deleted_journal'` |
| `Scanner` | `CHECK_JOURNAL = 'journal'` (Pass F) |
| CLI flag | `-d`, `--deleted-journal` |
| `ReportWriter::SCENARIOS` | `7 => 'Deleted journal leftovers'` |
| Export slug | `deleted_journal` |
| `Fixer` counters | `journalRecordsDeleted`, `alreadyRemoved` |

The interactive menu range `[1-6]` becomes `[1-7]` in both prompt strings and the bounds check in
`interactiveLoop()`. `--all` gains `CHECK_JOURNAL`.

## Safety

`--fix` on this scenario reuses the existing three-stage TTY confirmation gate
(`confirmReviewFix()`'s pattern: awareness → second confirmation → type `DELETE`), refusing to run
with piped STDIN. The prompt states the dead-journal count and the total row count about to be
deleted.

## Error handling

- Per-table query failure: `$this->warnings[]` plus `status = 'error'` on that table's result,
  matching Pass C and Pass D behaviour. The pass continues.
- Per-journal fix failure: transaction rollback for that journal, warning recorded, remaining
  journals proceed.
- Missing table (plugin uninstalled, older schema): `tableExists()` returns false, the table is
  skipped silently, exactly as the existing gateway methods do.

## Verification

The tool has no automated test suite, so verification is manual and staged:

1. Read-only `--deleted-journal` against the target database; confirm the summary table shows
   scenario 7 and the drill-down lists plausible tables.
2. Press `s` to export the full row list; spot-check several rows against the database by hand.
3. Restore a dump to a scratch database, run `--deleted-journal --fix`, confirm the three-stage
   prompt appears and that a declined prompt aborts without writing.
4. Re-run read-only on the scratch database; scenario 7 must report zero findings.
5. Re-run `--all`; confirm no new orphan findings appeared as a side effect of the cascade.

## Documentation

`README.md` gains: the `-d` flag row, a fix-mode table row, a numbered entry under "What It Does"
explaining the `SchemaDAO::deleteById` gap, a `deleted_journal` row in the Finding Reasons
Reference with severity **High**, and updated example output showing seven scenarios.
