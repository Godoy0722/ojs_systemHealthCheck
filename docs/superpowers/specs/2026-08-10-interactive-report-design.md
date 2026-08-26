# Interactive Report Output

**Date:** 2026-08-10
**Status:** approved

## Goal

Replace the current one-shot stdout dump with an interactive summary-first
report. When many records are found, the user sees counts first, then drills
into specific scenarios on demand.

## Non-goals

- No changes to scan logic, Finding model, Scanner, SchemaRegistry, Gateway, or Fixer
- No CSV/JSON/HTML export
- No pagination within a scenario (user explicitly asked for full rows)

## Design

### Scenarios (6)

Findings are grouped by reason code into a numbered menu:

| # | Scenario                  | Reason constant(s)              |
|---|---------------------------|---------------------------------|
| 1 | Missing locale (schema)   | `REASON_SCHEMA_MISSING_LOCALE`  |
| 2 | Missing locale (heuristic)| `REASON_HEURISTIC_LOCALE_MISMATCH` |
| 3 | Orphaned settings         | `REASON_ORPHAN_ENTITY`          |
| 4 | Required fields NULL      | `REASON_REQUIRED_NULL`          |
| 5 | NULL setting_value        | `REASON_SETTING_VALUE_NULL`     |
| 6 | REVIEW_REVISION files     | `REASON_REVIEW_REVISION`        |

Scenarios with 0 records are shown dimmed but still numbered (keeps menu
stable regardless of scan results).

### Flow

```
scan() → computeStats() → renderInteractive()
                            │
                            ├─ renderSummaryTable()
                            │
                            └─ loop (STDIN)
                                 ├─ [1-6] → renderScenarioDetail()
                                 │           └─ Enter → back to menu
                                 └─ q → exit
```

### File changes

**`src/ReportWriter.php`:**

- Delete `renderFindingsDetail()` and the `RULE_SINGLE` constant
- Replace `renderSummary()` with `renderInteractive(array $context): void`
  (prints directly, returns nothing)
- `renderInteractive()`:
  1. Groups `$context['findings']` by reason → 6 buckets
  2. Calls `renderSummaryTable(array $buckets): void` — prints the menu
  3. Enters `while(true)` reading `STDIN`
  4. On valid number: calls `renderScenarioDetail(int $scenario, array $findings, array $tableResults): void`
  5. On 'q': breaks, prints exit message
- `renderSummaryTable()`: ASCII table with scenario name, table count, record count
- `renderScenarioDetail()`: prints all findings for that scenario, grouped by table,
  using existing `describeReason()` and `truncate()` helpers. No row cap.

**`settingsHealthCheck.php`:**

- Replace `echo $writer->renderSummary($context)` with `$writer->renderInteractive($context)`

### Summary table format

```
──────────────────────────────────────────────────
  Scan summary — 632 findings across 6 scenarios
──────────────────────────────────────────────────
  #  Scenario                          Tables   Records
───  ────────────────────────────────  ──────   ───────
  1  Missing locale (schema)              12       342
  2  Missing locale (heuristic)            3        28
  3  Orphaned settings                     8       156
  4  Required fields NULL                  2         5
  5  NULL setting_value                    5        89
  6  REVIEW_REVISION files                 1        12
──────────────────────────────────────────────────
  Enter number [1-6] to see details, 'q' to quit:
```

Empty scenarios shown dimmed with 0 tables / 0 records.

### Scenario detail format

```
──────────────────────────────────────────────────
  Scenario 1: Missing locale (schema) — 342 records across 12 tables
──────────────────────────────────────────────────
  Table: author_settings (45 issues)
    Row #123  (author_id = 456)
      Problem : A multilingual field was stored without a locale tag...
      Field   : biography  (no locale tag)
      Value   : Dr. Smith is a professor of...
      Suggest : tag this row with locale "en"
    ...
  Table: journal_settings (28 issues)
    ...
──────────────────────────────────────────────────
  Press Enter to return to menu, or 'q' to quit:
```

No row cap — user explicitly asked for full detail on the selected scenario.

### Interactive rules

- At menu: `1`-`6` shows scenario. `q` or `Q` exits. Anything else → "Invalid. Enter 1–6 or q."
- At detail: `Enter` returns to menu. `q` or `Q` exits. Anything else → re-prompt.
- Exit message: "Done."

### Edge cases

- **Zero findings total**: prints "No findings." and exits immediately (no menu)
- **Some scenarios empty**: shown dimmed, still selectable (shows "No records in this scenario.")
- **STDIN not a TTY** (piped input): degrades gracefully — prints summary table only, skips interactive loop
- **Only one check ran**: only scenarios relevant to that check show records; others show 0 (dimmed)
