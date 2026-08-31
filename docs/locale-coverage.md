# Locale check coverage (`--locale`)

Reference for the locale pass (`-l` / `--locale`). Canonical sources: `src/SchemaRegistry.php`, `src/Scanner.php`.

This document is separate from the main usage guide (`../README.md`).

---

## What `--locale` looks for

Malformed locale tags on multilingual settings rows: `locale` is empty (`''`) or `NULL` on an existing row. These are data-corruption cases that can break PHP 8 hydration.

Also flags mixed-locale patterns on unmapped tables (heuristic): the same `setting_name` has both empty-locale rows and properly tagged rows.

Any schema-marked multilingual field with a bad locale tag is in scope, whether or not the field is required.

---

## What `--fix` does

Retags existing bad rows: stamps the site's primary locale onto rows where `locale` is empty or `NULL`.

---

## Pass A — schema-driven (high severity)

Reads OJS 3.3 JSON schemas (`lib/pkp/schemas/` + `schemas/`) for properties with `"multilingual": true`. For each mapped `*_settings` table, flags rows where that `setting_name` has empty/null `locale`.

**Tables covered (Pass A on OJS 3.3):**

- `announcement_settings`
- `author_settings`
- `journal_settings`
- `email_templates_settings`
- `issue_settings`
- `publication_settings`
- `site_settings`
- `submission_file_settings`

**Fix suggestion:** site primary locale (e.g. `en`).

---

## Pass B — heuristic (medium severity)

Runs on every other `*_settings` table with a `locale` column outside Pass A. Flags `setting_name` values that have **both** empty-locale rows **and** properly tagged rows in the same table.

Examples of heuristic-only tables on a typical OJS 3.3 install:

- `section_settings`, `user_settings`, `user_group_settings`
- `submission_settings`, `publication_galley_settings`
- `category_settings`, `genre_settings`, `review_form_settings`, `navigation_menu_item_settings`, `static_page_settings`, and other plugin/legacy settings tables

**Fix suggestion:** site primary locale on `--fix`.

---

## LocaleObject fields (Pass B fallback)

OJS 3.3 marks some entities with `"$ref": "#/definitions/LocaleObject"` instead of `"multilingual": true`. Pass B may catch bad rows when the mixed-locale pattern appears:

- **Section** — `abbrev`, `title` (`section_settings`)
- **User** — `affiliation`, `biography`, `familyName`, `givenName`, `gossip`, `signature` (`user_settings`)
- **User group** — `abbrev`, `name` (`user_group_settings`)

Heuristic-only when applicable:

- `galley` → `publication_galley_settings`
- `submission` → `submission_settings`

---

## Finding reason codes

| Code | Meaning |
|------|---------|
| `schema_missing_locale` | Pass A — known multilingual field, empty/null locale |
| `heuristic_locale_mismatch` | Pass B — same setting name has both tagged and empty-locale rows |

---

## Example scenarios

- `publication_settings`: `setting_name = abstract`, `locale = ''`, value present → Pass A
- `user_settings`: `biography` has rows with `locale = 'en'` and other rows with `locale = ''` → Pass B
