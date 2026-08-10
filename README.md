# Settings Health Check

This is a CLI diagnotic tool for OJS 3.3.X that goals to analyse existing data corruption on the database and, if requested, fix the errors based on pre selected scenarios.

## Usage

Run from the OJS root directory:

```bash
php tools/settingsHealthCheck/settingsHealthCheck.php [--flags]
```

, where the possible tags are listed below:

### Flags

| Flag | Description |
|------|-------------|
| `-l`, `--locale` | Missing translations — multilingual fields stored without a locale tag |
| `-o`, `--orphan` | Orphaned settings — rows whose parent entity no longer exists |
| `-e`, `--empty`  | Empty fields — required columns that are `NULL` + settings with `NULL` values |
| `-r`, `--review` | Review revision files — files stuck in `REVIEW_REVISION` status (causes fatal error on journal deletion) |
| `-a`, `--all`    | Run all checks above |
| `-h`, `--help`   | Show usage message |

You can combine flags. E.g.: `--orphan --empty` runs both.

### Fix mode

Add `-f` or `--fix` to apply remediations:

| Finding type | Fix applied |
|-------------|-------------|
| Orphaned rows | Are **deleted** from the database |
| Missing locales | The tool stamps with the site's primary locale |
| Empty fields | **Skipped** — no safe automatic fix; reported for manual review |
| Review revision files | Files and all associated DB records are **deleted** after 3-stage confirmation |

> **Important Notes**
>
> 1. `--fix` writes to the database. Always run read-only first.
> 2. The **Empty fields** scenario does not have an automatic fix because empty fields can come from plugin databases, and it's not a scenario that can be automatically fixed in a first approach.

## What It Does

The tool scans every `*_settings` table in the database across 5 passes, looking for data that can break the application or block maintenance operations:

1. **Missing locale tags** — Multilingual fields (like journal descriptions or author bios) stored without a locale code. These cause `TypeError` crashes in PHP 8.

2. **Orphaned settings** — Rows whose parent entity was deleted. Leftover data that clutters the database and can surface in unexpected places.

3. **Empty required fields** — Columns the schema says must have a value, but contain `NULL`. Also catches `NULL` setting values, which indicate a write that was never completed.

4. **Review revision files** — Submission files stuck with `file_stage = 15` (`SUBMISSION_FILE_REVIEW_REVISION`). These files cause a **fatal error** when deleting the submission or journal via the OJS command line, because the notification system tries to use the HTTP request context that does not exist in CLI mode.

5. **Untracked tables** — Tables without a matching JSON schema are checked heuristically for mixed-locale patterns, flagging rows that look out of place.

## Output

The tool prints an interactive report. Warnings (schema parse failures, query errors) go to **stderr**.

### Summary table

Shows a compact overview first — one row per scenario with table and record counts:

```
┌──────────────────────────────────────────────────────────────┐
│        Settings Health Check — Scan Results                  │
├──────────────────────────────────────────────────────────────┤
│  #  Scenario                                   Tables  Records │
├──────────────────────────────────────────────────────────────┤
│  1  Missing locale (schema-driven)                 12      342 │
│  2  Missing locale (heuristic)                      3       28 │
│  3  Orphaned settings                               8      156 │
│  4  Required fields NULL                            2        5 │
│  5  NULL setting_value                              5       89 │
│  6  REVIEW_REVISION files                           1       12 │
└──────────────────────────────────────────────────────────────┘
  Total: 632 findings across 6 scenarios

  Enter [1-6] to see details, [q] to quit:
```

Empty scenarios appear dimmed. If no findings exist at all, the tool prints `No findings — database looks clean.` and exits.

### Drill-down detail

Pick a number to see every row in that scenario, grouped by table:

```
──────────────────────────────────────────────────────────────────
  Scenario 1: Missing locale (schema-driven)
  342 records across 12 tables
──────────────────────────────────────────────────────────────────

  ▸ author_settings  (45 issues)

    Row #123      (author_id = 456)
      Problem : A multilingual field was stored without a locale tag. PHP 8
                cannot hydrate this value and will throw a TypeError.
      Field   : biography  (no locale tag)
      Value   : Dr. Smith is a professor of computational linguistics...
      Suggest : tag this row with locale "en"

    ...

  ▸ journal_settings  (28 issues)
    ...

──────────────────────────────────────────────────────────────────

  [Enter] menu  |  [s] save to file  |  [q] quit:
```

### File export

Press `s` at the post-detail prompt to save the current scenario as a plain-text file (no ANSI escape codes). The file is written to the current working directory:

```
  Saved: /path/to/ojs/settingsHealthCheck_locale_schema_20260810_143022.txt
```

You can export multiple scenarios in one session.

## Example: Interactive Session

```bash
$ php tools/settingsHealthCheck.php --all

┌──────────────────────────────────────────────────────────────┐
│        Settings Health Check — Scan Results                  │
├──────────────────────────────────────────────────────────────┤
│  #  Scenario                                   Tables  Records │
├──────────────────────────────────────────────────────────────┤
│  1  Missing locale (schema-driven)                  3        9 │
│  2  Missing locale (heuristic)                      0        0 │
│  3  Orphaned settings                               2        4 │
│  4  Required fields NULL                            1        2 │
│  5  NULL setting_value                              0        0 │
│  6  REVIEW_REVISION files                           0        0 │
└──────────────────────────────────────────────────────────────┘
  Total: 15 findings across 3 scenarios

  Enter [1-6] to see details, [q] to quit: 1

──────────────────────────────────────────────────────────────────
  Scenario 1: Missing locale (schema-driven)
  9 records across 3 tables
──────────────────────────────────────────────────────────────────

  ▸ announcement_settings  (2 issues)

    Row #1      (announcement_id = 42)
      Problem : A multilingual field was stored without a locale tag...
      Field   : description  (no locale tag)
      Value   : <p>Call for Papers: Special Issue on Digital Humanities</p>
      Suggest : tag this row with locale "en"

    Row #2      (announcement_id = 42)
      Problem : A multilingual field was stored without a locale tag...
      Field   : title  (no locale tag)
      Value   : Special Issue CFP
      Suggest : tag this row with locale "en"

  ▸ journal_settings  (1 issue)

    Row #1      (journal_id = 7)
      Problem : A multilingual field was stored without a locale tag...
      Field   : contactEmail  (no locale tag)
      Value   : editor@example.org
      Suggest : tag this row with locale "en"

  ▸ user_settings  (6 issues)
    ...

──────────────────────────────────────────────────────────────────

  [Enter] menu  |  [s] save to file  |  [q] quit: s

  Saved: /var/www/ojs/settingsHealthCheck_locale_schema_20260810_143022.txt

  [Enter] menu  |  [s] save to file  |  [q] quit:

  Enter [1-6] to see details, [q] to quit: q

  Done.
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


## License

GNU GPL v3. See `docs/COPYING` in the OJS root.
