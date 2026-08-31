# Orphan check entity coverage (`--orphan`)

Reference for the orphan pass (`-o` / `--orphan`). Canonical sources:

- Settings orphans: `src/SettingsFkRegistry.php`
- Entity-table orphans (live journals): `src/EntityReferenceRegistry.php`

This document is separate from the main usage guide (`../README.md`).

---

## What `--orphan` looks for

1. **Settings orphans** — rows in `*_settings` tables whose parent entity no longer exists
2. **Entity orphans** — invalid FK columns on non-settings entity tables inside **live** journals
3. **Stored reference orphans** — invalid `issueId` values in `publication_settings`
4. **Blob orphans** — unreferenced rows in the central `files` table

---

## Parent entities checked (settings rows)

One bullet per scenario — settings left behind after the parent was deleted:

- Announcement
- Announcement type
- Author
- Book for review
- Category
- Citation
- Controlled vocabulary entry
- Data object tombstone
- Deposit point
- Email template
- Event log entry
- External feed
- Filter
- Genre
- Group
- Issue
- Issue galley
- Journal
- Library file
- Metadata description
- Navigation menu item
- Navigation menu item assignment
- Notification
- Notification subscription user
- Notification subscription journal (`context`; site-wide `0` is ignored)
- Object-for-review assignment
- Review object metadata field
- Plugin journal scope (`context_id`; site-wide `0` is ignored)
- Publication
- Publication galley
- Referral
- Review form
- Review form element
- Review object metadata
- Review object type
- Section
- Static page
- Submission
- Submission file
- Subscription type
- User
- User group

---

## Entity tables checked (live journals)

Invalid FK columns scoped to journals that still exist. Examples:

- `submissions.current_publication_id` → missing publication
- `submission_files.submission_id` → missing submission
- `publications.submission_id` → missing submission
- `authors.publication_id` → missing publication
- `publication_galleys.publication_id` → missing publication
- `review_assignments.submission_id` → missing submission
- `citations.publication_id` → missing publication
- `library_files.submission_id` → missing submission
- `plugin_settings.context_id` → missing journal (when non-zero)

Full rule list: every entry in `EntityReferenceRegistry::rules()`.

Fix order on `--fix`: repoint `current_publication_id` and `section_id` first, then delete rows or set invalid FK columns to NULL per rule action.

---

## Additional checks within `--orphan`

- **Publication issue link** — `publication_settings` rows where `setting_name = issueId` and the stored issue no longer exists
- **Unreferenced blob files** — rows in `files` not referenced by `submission_files.file_id` or `submission_file_revisions.file_id`

---

## Explicitly excluded

- **Site settings** (`site_settings`) — global key/value store with no parent entity FK

---

## Tables scanned

**Settings:** all OJS `*_settings` tables with a resolvable parent FK (40 tables). Includes tables without a `locale` column (`event_log_settings`, `plugin_settings`, `notification_subscription_settings`, `object_for_review_settings`).

Plugin-added `*_settings` tables not listed in the registry still fall back to DB constraints or naming conventions when present in the live schema.

**Entities:** non-settings tables declared in `EntityReferenceRegistry`.

---

## Not part of `--orphan`

| Flag | Scope |
|------|--------|
| `-d` / `--deleted-journal` | Full leftover tree after journal deletion (Pass F) |
