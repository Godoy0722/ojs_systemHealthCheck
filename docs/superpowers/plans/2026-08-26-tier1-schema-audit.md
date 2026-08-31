# Tier 1 Schema Audit — PreflightCheck Alignment

Date: 2026-08-26  
Database: `ojs_3_3_0`  
Live journals: **#22** (`ajn`)  
Audit script: `docs/superpowers/scripts/tier1-schema-audit.php`  
Reference plan: `docs/superpowers/plans/2026-08-26-preflight-check-alignment-plan.md`

## Purpose

Verify which PKP `getEntityRelationships` / OJS processor candidates **exist on this OJS 3.3 install** and how many **dead-journal leftover rows** each would add to Pass F coverage.

Run again after schema changes:

```bash
php docs/superpowers/scripts/tier1-schema-audit.php | jq .
```

---

## Current registry health

All tables declared in `JournalCascadeRegistry` exist in the live database. After Tier 1 expansion (2026-08-26): **32 tables**, **5,842,377** dead-journal leftover rows on `ojs_3_3_0` (was 25 tables / ~5,792,354).

---

## Summary — recommended Tier 1 additions (by impact)

| Priority | Table / chain | Dead-journal rows | Action |
|---|---|---:|---|
| **P1** | `submission_file_revisions` ← `submission_files` | **28,957** | ~~Add child~~ **Done** |
| **P1** | `queries` ← `submissions` | **11,453** | ~~Add child~~ **Done** (`assoc_type` filter) |
| **P1** | `query_participants` ← `queries` | **18,823** | ~~Add child~~ **Done** |
| **P1** | `files` ← `submission_files` | **26,879** | Defer — shared blob table; needs unreferenced-only delete |
| **P2** | `publication_settings.issueId` (Tier 2) | **4,155** | ~~Pass G~~ **Done** (merged into `--orphan` / Pass C) |
| **P2** | `static_pages` (journal root) | **5** | ~~Add direct root~~ **Done** |
| **P3** | `library_file_settings` ← `library_files` | **222** | ~~Add child~~ **Done** |
| **P3** | `filter_settings` ← `filters` | **29** | ~~Add child~~ **Done** |
| **P3** | `data_object_tombstones` → `data_object_tombstone_settings` | **13** / **3** | ~~Add tombstone chain~~ **Done** |
| **P4** | `navigation_menu_item_assignments` via `navigation_menus` | **7** | Optional alt path (already reachable via `navigation_menu_items`) |
| **P4** | `subeditor_submission_group` (journal root) | **0** | ~~Add root~~ **Done** |
| **—** | `submission_search_object_keywords` | **0** | ~~Add child~~ **Done** |
| **—** | `review_form_responses` via `review_assignments` | **0** | Optional alt path (already via `review_form_elements`) |
| **—** | `publication_categories` | — | Already in registry under `publications` |
| **—** | `notification_subscription_settings` | — | **Exclude** (string `context`) |

**Estimated new Pass F coverage (excluding deferred `files`): ~59,500 rows** on this database.

---

## Detailed checklist

### Journal roots (PKP `journals => [...]`)

| Table | Exists | Journal column | PK / identity | Dead rows | Add? |
|---|---|---|---|---:|---|
| `subeditor_submission_group` | Yes | `context_id` | Composite PK: `context_id`, `assoc_type`, `assoc_id`, `user_id` | 0 | **Yes** — use `context_id` + aggregate identity |
| `static_pages` | Yes | `context_id` | `static_page_id` | 5 | **Yes** |
| `notification_subscription_settings` | Yes | `context` (string) | `setting_id` | — | **No** — deliberate exclusion |

### Descendant chains

| Parent | Child | FK column | FK exists | Dead rows | Add? | Notes |
|---|---|---|---|---:|---|---|
| `submission_files` | `submission_file_revisions` | `submission_file_id` | Yes | 28,957 | **Yes** | PK: `revision_id`; also references `files.file_id` |
| `submission_files` | `submission_file_settings` | `submission_file_id` | Yes | *(in cascade)* | **Verify** | Registry already has this child; FK is `submission_file_id` on this DB |
| `submission_search_objects` | `submission_search_object_keywords` | `object_id` | Yes | 0 | **Yes** | Composite PK: `object_id`, `pos` |
| `filters` | `filter_settings` | `filter_id` | Yes | 29 | **Yes** | Composite settings PK |
| `library_files` | `library_file_settings` | `file_id` | Yes | 222 | **Yes** | Composite settings PK |
| `navigation_menus` | `navigation_menu_item_assignments` | `navigation_menu_id` | Yes | 7 | Optional | Already under `navigation_menu_items` |
| `review_assignments` | `review_form_responses` | `review_id` | Yes | 0 | Optional | Already under `review_form_elements` |
| `submissions` | `queries` | `assoc_id` | Yes | 11,453 | **Yes** | Filter `queries.assoc_type = 0x0100009` (`ASSOC_TYPE_SUBMISSION`) |
| `queries` | `query_participants` | `query_id` | Yes | 18,823 | **Yes** | Composite PK: `query_id`, `user_id` |
| `publications` | `publication_categories` | `publication_id` | Yes | — | **Done** | Already in registry |
| `categories` | nested `categories` | `parent_id` | Yes | — | **Defer** | Root already journal-scoped; nested delete implicit when parent categories go |
| `filters` | nested `filters` | `parent_filter_id` | Yes | — | **Defer** | Root already journal-scoped |

### Tombstones

| Table | Journal column | Link to dead journal | Dead rows | Add? |
|---|---|---|---:|---|
| `submission_tombstones` | `journal_id` | Direct root | *(in Pass F)* | **Done** |
| `data_object_tombstones` | None | `data_object_id` → `submissions.submission_id` | 13 | **Yes** — child of `submissions`, not a root |
| `data_object_tombstone_settings` | None | via tombstone | 3 | **Yes** |
| `data_object_tombstone_oai_set_objects` | None | via tombstone | *(in Pass F as assoc root)* | Review — may duplicate OAI assoc root |

### Files (deferred)

| Table | Exists | Dead rows via dead-journal submissions | Add? |
|---|---|---:|---|
| `files` | Yes | 26,879 | **Defer** — blob table may be referenced across contexts; PKP cleans via orphan pass when unreferenced only |

---

## Tier 2 preview (not Pass F)

| Check | Rows | Mechanism |
|---|---:|---|
| `publication_settings` where `setting_name = 'issueId'` and value ∉ `issues` | **4,155** | OJS PreflightCheck ~L236–252; requires Pass G |

---

## Schema notes specific to this database

### `subeditor_submission_group`

```
PK: context_id, assoc_type, assoc_id, user_id
```

No surrogate id. Pass F should treat as **aggregate root** (same pattern as `plugin_settings` / `metrics` fallback): count rows by `context_id`, delete by `context_id` on fix.

### `submission_files` / `submission_file_settings`

This install uses the **3.4-style** `submission_files.submission_file_id` column (not the legacy composite `file_id` + `revision` PK from stock 3.3 XML). The current registry identity column is correct.

`submission_file_settings` FK is **`submission_file_id`**, not `file_id`.

### `queries`

Must scope to submission workflow queries only:

```sql
queries.assoc_type = 1048585  -- ASSOC_TYPE_SUBMISSION (0x0100009)
AND queries.assoc_id = submissions.submission_id
```

Pass F cascade walker needs an **`assoc_type` filter** on this step (new registry field or special-case in Scanner).

---

## Implementation order (after this audit)

1. ~~**Fixer hardening**~~ **Done** (2026-08-26).
2. ~~**Registry expansion**~~ **Done** (2026-08-26): roots + descendants + `assoc_type` on cascade steps.
3. **Defer `files`** until unreferenced-only delete strategy is defined.
4. ~~**Tier 2:** `publication_settings.issueId` (~4,155 rows) in Pass C (`--orphan`).~~ **Done** (2026-08-26).

---

## Open questions resolved / remaining

| Question | Result on `ojs_3_3_0` |
|---|---|
| Does `submission_file_revisions` exist? | **Yes** — 28,957 dead-journal rows |
| Does `files` exist separate from `submission_files`? | **Yes** — 26,879 blob rows linked via `submission_files.file_id` |
| `queries` journal linkage? | **Yes** — via `assoc_type` + `assoc_id` → submission |
| `static_pages` journal-scoped? | **Yes** — `context_id`, 5 dead rows |
| `data_object_tombstones` vs `submission_tombstones`? | **Both exist** — different tables; add `data_object_*` chain under submissions |
| Nested `categories` / `filters` needed? | **Defer** — journal roots already cover top-level rows |

---

## Raw audit output

Machine-readable JSON from the audit run is saved at:

`docs/superpowers/plans/tier1-audit-raw.json`

Regenerate with:

```bash
php docs/superpowers/scripts/tier1-schema-audit.php > docs/superpowers/plans/tier1-audit-raw.json
```
