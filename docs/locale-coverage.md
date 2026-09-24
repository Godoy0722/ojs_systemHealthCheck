# Locale check coverage (`--locale`)

Reference for the locale pass (`-l` / `--locale`). Canonical sources: `src/SchemaRegistry.php`, `src/Scanner.php`.

This document is separate from the main usage guide (`../README.md`).

---

## What `--locale` looks for

Malformed locale tags on multilingual settings rows: `locale` is empty (`''`), `NULL`, or not a locale OJS can install (for example `0` or `en`). A locale is valid when it matches the `xx_XX` format of `AppLocale::isLocaleValid()` and a directory for it exists under `locale/`. These are data-corruption cases that can break PHP 8 hydration.

Also flags mixed-locale patterns on unmapped tables (heuristic): the same `setting_name` has both empty-locale rows and properly tagged rows.

Any schema-marked multilingual field with a bad locale tag is in scope, whether or not the field is required.

---

## What `--fix` does

Resolves each invalid-locale row individually (`src/MissingLocaleResolver.php`), re-reading the database before every write so no field ends up with a duplicate locale and no empty locale remains:

1. The row's journal is resolved through the cascade paths in `src/JournalCascadeRegistry.php` (e.g. `author_settings > authors > publications > submissions.context_id`). Its locales are `supportedFormLocales` (fallback `supportedLocales`), primary locale first. Rows with no live journal (`site_settings`, `user_settings`, orphans, …) use the site's `supported_locales`.
2. The locales already stored for the same field (same unique-key columns except `locale`) are compared with those locales:
   - some journal locales are still missing → the row is retagged with the primary locale if it is missing, otherwise with the first missing one;
   - every journal locale is already set → the empty-locale row is a duplicate and is **deleted**.

With a single journal locale this means: retag when that locale is not yet set for the field, delete when it is.

---

## Pass A — schema-driven (high severity)

Reads OJS 3.3 JSON schemas (`lib/pkp/schemas/` + `schemas/`) for properties with `"multilingual": true`. For each mapped `*_settings` table, flags rows where that `setting_name` has an invalid `locale` (empty, null, or not installable).

**Tables covered (Pass A on OJS 3.3):**

- `announcement_settings`
- `author_settings`
- `journal_settings`
- `email_templates_settings`
- `issue_settings`
- `publication_settings`
- `site_settings`
- `submission_file_settings`

**Fix:** per-row journal locale resolution (see above).

---

## Pass B — heuristic (medium severity)

Runs on every other `*_settings` table with a `locale` column outside Pass A. Flags `setting_name` values that have **both** invalid-locale rows **and** rows tagged with an installable locale in the same table.

Examples of heuristic-only tables on a typical OJS 3.3 install:

- `section_settings`, `user_settings`, `user_group_settings`
- `submission_settings`, `publication_galley_settings`
- `category_settings`, `genre_settings`, `review_form_settings`, `navigation_menu_item_settings`, `static_page_settings`, and other plugin/legacy settings tables

**Fix:** per-row journal locale resolution on `--fix` (see above).

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
| `schema_missing_locale` | Pass A — known multilingual field, invalid locale |
| `heuristic_locale_mismatch` | Pass B — same setting name has both valid-locale and invalid-locale rows |

---

## Example scenarios

- `publication_settings`: `setting_name = abstract`, `locale = ''`, value present → Pass A
- `user_settings`: `biography` has rows with `locale = 'en'` and other rows with `locale = ''` → Pass B
