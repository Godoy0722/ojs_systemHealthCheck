# Settings Health Check

CLI diagnostic tool for OJS 3.3+ that scans every `*_settings` table for existing data corruption — missing locale tags that cause PHP 8 `TypeError` crashes, orphaned rows, empty required fields, and files stuck in `REVIEW_REVISION` status that block journal deletion.

## Background

OJS `*_settings` tables store multilingual fields as separate rows keyed by locale. When a row that should have a locale tag is stored with an empty or `NULL` locale, PHP 8 cannot hydrate the value and throws a `TypeError`. This tool detects those rows — and several other classes of data corruption — already present in the database.

## Requirements

- OJS 3.3.X
- PHP 7.4+
- MySQL/MariaDB or PostgreSQL

## Usage

Run from the OJS root directory:

```bash
php tools/settingsHealthCheck/settingsHealthCheck.php <check> [--fix]
```

### Checks

| Flag | Description |
|------|-------------|
| `-l`, `--locale` | Missing translations — multilingual fields stored without a locale tag |
| `-o`, `--orphan` | Orphaned settings — rows whose parent entity no longer exists |
| `-e`, `--empty`  | Empty fields — required columns that are `NULL` + settings with `NULL` values |
| `-r`, `--review` | Review revision files — files stuck in `REVIEW_REVISION` status (causes fatal error on journal deletion) |
| `-a`, `--all`    | Run all checks above |
| `-h`, `--help`   | Show usage message |

Checks combine: `--orphan --empty` runs both.

### Fix mode

Add `-f` or `--fix` to apply remediations:

| Finding type | Fix applied |
|-------------|-------------|
| Orphaned rows | **Deleted** from the database |
| Missing locales | Stamped with the site's primary locale |
| Empty fields | **Skipped** — no safe automatic fix; reported for manual review |
| Review revision files | Files and all associated DB records **deleted** after 3-stage confirmation |

**Important:** `--fix` writes to the database. Always run read-only first.

## What It Does

The scanner runs up to 5 detection passes:

1. **Pass A — Schema-driven locale check** — Parses entity JSON schemas from `lib/pkp/schemas/` and `schemas/` to identify multilingual properties. Queries the corresponding `*_settings` table for rows where those properties have an empty or `NULL` locale.

2. **Pass B — Heuristic locale check** — For `*_settings` tables not covered by a known schema, auto-discovers setting names that have both localized and non-localized rows (mixed-locale pattern), then flags the empty-locale ones as suspicious.

3. **Pass C — Orphan detection** — Resolves foreign-key relationships for each settings table (via `information_schema` constraints or naming conventions). Finds rows whose FK value has no matching row in the parent table.

4. **Pass D — Empty-field detection** — D1 checks main entity tables for required-but-`NULL` columns. D2 scans every settings table for rows where `setting_value IS NULL`.

5. **Pass E — Review revision files** — Finds `submission_files` rows with `file_stage = 15` (`SUBMISSION_FILE_REVIEW_REVISION`), which cause a fatal error (`updateNotification` called without request context) when deleting submissions or journals via CLI.

## Output

### Exit codes

| Code | Meaning |
|------|---------|
| `0`  | Clean — no findings |
| `1`  | Findings found — see stdout summary for details |
| `2`  | Error — check stderr for details |

### Stdout summary

Prints a table-by-table breakdown of findings. Example output is shown below.

Warnings (schema parse failures, query errors) go to **stderr**.

## Example: Read-Only Scan

```bash
$ php tools/settingsHealthCheck.php --all

  Database: ojs_production
  Tables scanned: 14   (12 schema-mapped + 2 auto-discovered)

────────────────────────────────────────────────────────
  Table-by-table
────────────────────────────────────────────────────────
  announcement_settings     schema     2 finding(s)  [findings]
  author_settings           schema     clean
  journal_settings          schema     1 finding(s)  [findings]  orphan: 3 finding(s)
  publication_settings      schema     clean
  section_settings          schema     clean
  site_settings             schema     clean
  submission_settings       schema     clean
  user_settings             schema     7 finding(s)  [findings]
  ...

────────────────────────────────────────────────────────────────────────────────
  Detailed findings (15)
────────────────────────────────────────────────────────────────────────────────

  Table: announcement_settings   (2 issues)

    Row #1  (announcement_id = 42)
      Problem : A multilingual field was stored without a locale tag. PHP 8 cannot
                hydrate this value and will throw a TypeError.
      Field   : description  (no locale tag)
      Value   : <p>Call for Papers: Special Issue on Digital Humanities</p>
      Suggest : tag this row with locale "en"

    Row #2  (announcement_id = 42)
      Problem : A multilingual field was stored without a locale tag.
      Field   : title  (no locale tag)
      Value   : Special Issue CFP
      Suggest : tag this row with locale "en"

  Table: journal_settings   (1 issue)

    Row #1  (journal_id = 7)
      Problem : A multilingual field was stored without a locale tag.
      Field   : contactEmail  (no locale tag)
      Value   : editor@example.org
      Suggest : tag this row with locale "en"

  ...
```

## Example: Fix Mode

```bash
$ php tools/settingsHealthCheck.php --locale --fix

  Database: ojs_production
  ...

  Fixes applied
  -------------
  Orphaned rows deleted : 0
  Missing locales set   : 11
  Review files deleted  : 0
  Empty fields skipped  : 0
```

## Example: Review Fix with Confirmation

```bash
$ php tools/settingsHealthCheck.php --review --fix

  Database: ojs_production
  ...

  ================================================================================
  WARNING: The scan found 2 file(s) under the REVIEW_REVISION status.
  Fixing these findings will permanently delete these files and their database records.
  ================================================================================

  Stage 1/3: Are you aware that this operation will delete data in the database? (yes/no): yes
  Stage 2/3: Do you really want to execute this operation in the database?
             This is your second confirmation. (yes/no): yes
  Stage 3/3: This is the final confirmation.
             This will permanently delete files and database records. Confirm by typing 'DELETE': DELETE

  Confirmation successful. Moving forward with the execution...

  Fixes applied
  -------------
  Orphaned rows deleted : 0
  Missing locales set   : 0
  Review files deleted  : 2
  Empty fields skipped  : 0
```

## Finding Reasons Reference

| Reason code | Severity | Description |
|-------------|----------|-------------|
| `schema_missing_locale` | **High** | Multilingual field missing locale tag — PHP 8 `TypeError` risk |
| `heuristic_locale_mismatch` | **Medium** | Setting has mixed localized/non-localized rows; empty-locale rows look out of place |
| `orphan_entity` | **Medium** | Row references a parent entity that no longer exists — dangling data |
| `required_null` | **High** | Schema-required column is `NULL` in the database — broken row written |
| `setting_value_null` | **Low** | `setting_value` is `NULL` — writer skipped this field |
| `review_revision` | **Critical** | File stuck in `REVIEW_REVISION` status — blocks journal/submission deletion with fatal error |

## Recommended Workflow

1. **Run read-only first** — `php tools/settingsHealthCheck.php --all`
2. **Review the stdout summary** — understand what will be changed
3. **Fix locale/orphan issues** — `php tools/settingsHealthCheck.php --locale --orphan --fix`
4. **Handle empty-field findings manually** — no auto-fix; review each row
5. **Fix review revision files with caution** — `php tools/settingsHealthCheck.php --review --fix` (requires 3 confirmations)

## Files

```
tools/settingsHealthCheck/
├── settingsHealthCheck.php   Entry point (CLI tool class)
├── README.md                 This file
└── src/
    ├── Finding.php           Value object for one flagged row
    ├── Scanner.php           Detection passes (A through E)
    ├── SchemaRegistry.php    Loads entity JSON schemas, builds locale/required maps
    ├── DatabaseGateway.php   Interface for DB read/write operations
    ├── IlluminateDatabaseGateway.php   Production implementation (Illuminate Capsule)
    ├── ReportWriter.php      Stdout summary rendering
    └── Fixer.php             Applies --fix remediations to findings
```

## License

GNU GPL v3. See `docs/COPYING` in the OJS root.
