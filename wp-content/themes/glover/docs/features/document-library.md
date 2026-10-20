# Document Library

## Status

Phase 1 (the core library) is built and verified locally. It includes the field contract, settings screen, submitter permissions, submission form with private pending storage, review queue, public library with live filtering, single document pages, board feeds, and the accessible-copy request form.

Phases 2 and 3 are built and verified live on the local site: passwordless sign-in at `/sign-in/`, Drive backup, and site email through the Gmail API. Setup and details: [Google Workspace integration](google-workspace.md).

Full-text search is built and verified locally (2026-10-20): the library's search box matches titles and the text inside published PDF, Word and Excel files, ranks title matches first, and shows snippets with page numbers. Approved from [the search proposal](document-search-proposal.md) and its mock-up (`project-files/mockups/document-search-results.html`, untracked). See "Full-text search" below.

The approved proposal, with its decision log and diagrams, is the Claude Doc "Glover Document Library Proposal" (https://claude.ai/code/artifact/f145edb5-b20d-4e67-b148-91b6b7a4ef7d). Jenny approved it in chat on 2026-10-19. This file is the maintained spec from here on.

Last updated: 2026-10-20.

## User need and purpose

Residents need one place to find the town's public documents: agendas, minutes, ordinances, policies, reports, plans, and notices. Most of them are not technical. Town staff and board secretaries need a simple way to post documents without managing passwords, and without anything going public until it has been checked.

The website, meaning the WordPress database and uploads, is the home of every document. Google Drive will hold an organized backup copy and is never the public link.

## Approved scope

- The Document Library data model: post type, fields, taxonomies, documents tagged with one or more boards, and a settings screen.
- One document submission form, built as an ACF front-end form, with an accessibility and legal requirements guide opened from a large button at the top of the page.
- A review queue for the designated approver, with a per-person Auto-approve setting that publishes without review.
- Sign-in through the standard WordPress login now. Later, passwordless sign-in: "Sign in with Google" for @townofglover.com accounts and an emailed one-time code for everyone else.
- The public library at `/documents/`, with live filtering, a search help modal, and stable single document pages.
- A "Latest documents" feed on every Departments & Boards page.
- One-way backup of approved documents to a town-owned Google Shared Drive (Phase 3).
- Site email sent through the town's Google Workspace.
- Full-text search inside published PDF, Word and Excel files, with an approver's text check and settings for what search leaves out.
- No new paid licenses or add-ons. Anything a paid add-on would do is written in-house.

## Current non-goals

- Anonymous or public uploads.
- Drive as the public file host, or any sync from Drive back to the site.
- OCR of scanned PDFs (decided 2026-10-20). Scans are found by title only.
- Removing names, street addresses or phone numbers from search automatically. Specific ones can be listed as excluded words.
- Remediating inaccessible PDFs.
- Linking documents to Meetings or meeting dates (deferred, unscheduled).
- Bulk migration of legacy documents (a later phase with its own sign-off).
- A file-type filter.
- Gravity Forms.

## Source evidence

- The approved proposal Doc linked above, including its "Decided on 2026-10-19" table.
- Figma document library mockup: https://www.figma.com/design/u54UbM7RobQdYf22TsF88W/Glover?node-id=1-3. The mockup is never the source of truth for content such as category names.
- DNS lookup on 2026-10-19: townofglover.com mail is hosted by Google Workspace.

## How it works

1. A signed-in submitter opens the Submit a Document page and reviews the requirements guide.
2. They submit the form. The file is uploaded straight into the private folder, and the document is saved as Pending.
3. If the submitter has Auto-approve, the document publishes immediately. Otherwise the approver and backup approver get an email, and the Document Library menu shows a pending count.
4. The approver previews the file through a permission-checked link, then chooses Approve or Return (with a reason that is emailed to the submitter).
5. On publish, the file moves to `uploads/documents/<year>/`, a slug is set, the submitter is emailed, and `glover_document_library/document_published` fires. That hook is where the Drive backup will attach.
6. If the document replaces an earlier one, its file, date, minutes status, boards, category and topics move into the original document. The original keeps its address, the old file is kept privately in its version history, and the replacement submission is retired to the trash.

## Data and field contracts

### Post type

- Post type: `document-library` (`acf-json/post_type_6ad6258d36975.json`).
- Public archive and single URLs: `/documents/` and `/documents/<slug>/`. The slug is set once on first publish and never changes on replacement.
- Admin labels use "Document" and "Documents". The menu reads "Document Library".
- Only the approver, the backup approver, administrators, and Auto-approve submissions can publish. Anyone else's publish attempt is saved as Pending (`wp_insert_post_data` guard).

### Field group: Document Fields

`acf-json/group_6a7b16a4deb17.json`, located on `document-library`. Meta names are contracts.

| Meta name | Type | Required | Notes |
| --- | --- | --- | --- |
| `document_file` | File (pdf, docx, xlsx), returns ID | Yes | Size cap from settings. WordPress rejects files whose content doesn't match their extension |
| `document_boards` | Checkboxes of board post IDs | Yes | Public form lists only the submitter's boards. Mirrored into `document-board` on save |
| `document_category` | Taxonomy radio (`document-category`) | Yes | Saves real term relationships |
| `document_topics` | Taxonomy checkboxes (`document-subject`) | No | Saves real term relationships. No in-field term creation |
| `document_suggested_topic` | Text | No | Emailed to the approver |
| `document_date` | Date picker, stored `Ymd` | Yes | Drives Year, sorting and display. Formatted in the site time zone |
| `document_minutes_status` | `draft` / `approved` | When the category slug is `minutes` | Shown only for Minutes; required by PHP validation |
| `document_replaces` | Select of recent published document IDs | No | Public form lists the submitter's boards only, validated server-side |
| `document_accessibility_confirmed` | True/false | Yes | |
| `document_legal_confirmed` | True/false | Yes | |
| `document_reviewer_note` | Textarea | No | |
| `document_exclude_from_text_search` | True/false | No | Approvers and administrators only (hidden from everyone else, and protected on save). Keeps the file's text out of search; the title is still searched. Not on the submission form |

System meta, written by code and never edited by hand:

| Meta key | Purpose |
| --- | --- |
| `_glover_submitted_by` | User ID of the submitter |
| `_glover_auto_published` | 1 when published without review |
| `_glover_possible_duplicates` | IDs with the same category, date and a shared board |
| `_glover_returned_reason` | The approver's reason, when returned |
| `_glover_previous_files` | Version history: attachment, replaced time, replacing submission |
| `_glover_merged_into` | On a retired replacement, the document it updated |
| `_glover_audit_log` | Time, user, action, note for every step |
| `_glover_drive_backup_status`, `_glover_drive_backup_note`, `_glover_drive_file_id` | Drive backup tracking. The ID, never a URL |
| `_glover_text_index_status` | Text search: `queued`, `indexed`, `no_text`, `failed`, `excluded`. Absent when not published |
| `_glover_text_index_note` | How the file was read and how many pages, or the error |
| `_glover_text_index_hash` | SHA-1 of the indexed file plus the settings signature, so unchanged files are skipped and a settings change re-indexes |

### Taxonomies

All three are flat, attached only to `document-library`, visible in REST, and have no public archives or rewrites. Their native meta boxes are disabled so the ACF fields manage assignment.

- `document-category`: the formal grouping, one per document. The terms in WordPress are the source of truth. As of 2026-10-19 the local site has Agendas, Minutes, Ordinances, Bylaws, Reports, Forms, Plans & Studies, and Maps. Minutes status depends on the `minutes` slug.
- `document-subject` ("Topics"): informal tags shown as chips and in the active-filter rail. A controlled list; submitters can suggest additions.
- `document-board`: hidden mirror of Departments & Boards with no admin UI. Each term's slug is `board-<post ID>`, so renaming a board never breaks it. Terms are created and renamed when a board is saved, and deleted when it is trashed.

### Settings

Document Library > Settings (`acf-json/group_glover_document_library_settings.json`, options page registered in PHP), admins only:

| Setting | Meta name (options) | Notes |
| --- | --- | --- |
| Approver | `document_library_approver` | Required. Gets review emails |
| Backup approver | `document_library_backup_approver` | Optional. Same rights and emails |
| Upload size cap (MB) | `document_library_upload_cap` | Blank means the host's limit |
| Help contact name, phone, email | `document_library_help_contact_*` | Shown in the help dialog and empty results. The email also receives accessible-copy requests |
| Requirements guide page | `document_library_requirements_page` | The page the requirements dialog shows. The clerk keeps it current |
| Turnstile site and secret keys | `document_library_turnstile_*` | Optional bot check for the request form |
| Remove email addresses (Search tab) | `document_library_search_remove_emails` | On unless turned off. Addresses at the town's domain are always kept |
| Excluded words and phrases (Search tab) | `document_library_search_excluded_terms` | Commas or new lines. Whole words, any capitals, entries under three characters ignored. Never shown publicly |
| Search status (Search tab) | message field, no value | Counts by status linking to filtered lists, which PDF reader is in use, and Re-index all |

The fields sit under General and Search tabs.

### Submitters

A submitter is a WordPress user, so Phase 2 sign-in only has to sign people in as users. The Document Submitter role has `read` and `submit_glover_documents`; administrators and editors are also granted the capability. On each user's profile (`acf-json/group_glover_document_submitter.json`, visible to administrators only), the settings are:

- `document_submitter_boards`: the boards they may post for. Empty means every board for approvers, administrators and editors.
- `document_submitter_auto_approve`: publish without review.

Only users who can `promote_users` can change these, even on their own profile.

### Accessible-copy requests

`access-copy-request` (`acf-json/post_type_glover_access_copy_request.json`), private, listed under Document Library. Each request is saved before it is emailed, so a mail failure never loses it. The form has a honeypot, a five-per-hour limit per IP address, and an optional Turnstile check.

## Extension points for Google

Both are now used by the [Google Workspace integration](google-workspace.md).

- **Sign-in:** filter `glover_document_library/sign_in_url`. The must-use plugin points it at `/sign-in/`.
- **Backup:** register on `glover_document_library/backup_handlers`, handle `glover_document_library/backup_requested ($post_id, $attachment_id)`, and record progress with `glover_document_library_set_backup_status()`. `document-library-drive.php` does this when Drive is configured; otherwise the status is "Not configured".
- **Lifecycle actions:** `glover_document_library/document_published`, `document_unpublished`, and `document_replaced ($post_id, $new_attachment_id, $previous_attachment_id)`.

## Full-text search

### How it works

1. A document is published or replaced. `document-library-search-index.php` sets its status to Queued and schedules a WP-Cron job for it.
2. The job checks that the document is still published, not excluded, not a retired replacement, and that its current file is out of the private folder. Otherwise it removes any rows and stops.
3. It reads the file (`document-library-search-extract.php`): PDFs page by page with poppler's `pdftotext` when the host can run it, otherwise with the bundled smalot/pdfparser; Word files as one block; spreadsheets one part per sheet. Whitespace is collapsed.
4. It removes email addresses outside the town's domain (`GLOVER_GOOGLE_DOMAIN`, default townofglover.com) and the excluded words and phrases, then replaces the document's rows in one transaction.
5. A PDF averaging under 25 characters a page gets No text (almost always a scan) and no rows. Read errors give Failed with the message.

Pending, returned, draft, private and previous-version files are never read for the index. Every search query is also limited to published posts by `WP_Query`.

### Data contract: `{prefix}glover_document_text`

Created by `dbDelta` on `init` once per `GLOVER_DOCUMENT_SEARCH_DB_VERSION` (option `glover_document_search_db_version`), with MySQL's built-in stopword list turned off for the session so words like "will" are kept.

| Column | Notes |
| --- | --- |
| `post_id` | The document |
| `attachment_id` | The file the text came from |
| `part` | 0 = the title plus all the text, used for matching; 1 and up = pages or sheets, used for snippets |
| `part_label` | "Page 4", a sheet name, or empty for Word files |
| `source` | `pdftotext`, `pdfparser`, `docx` or `xlsx` |
| `content` | Clean, scrubbed text with a FULLTEXT index |

### Matching, ranking and snippets

- `document-library-search-query.php` replaces the old title-only search. A document matches when every search word is in its title (`LIKE`, as before) or its part 0 text matches a MySQL Boolean-mode query in which each word is `+word*` and each quoted phrase is `+"a phrase"`. Operator characters are stripped, words under three characters are left to the title match, and curly quotes are treated as straight ones.
- Sort `relevance` ("Best match") is the default whenever there is a search, and is left out of URLs then. It orders by every word in the title, some words in the title, the text score, then the date order. Most recent, Oldest and Title work as before. `library.js` keeps the Sort menu in step until someone picks a sort.
- The category and topic counts include text matches, because facets reuse the same query arguments.
- Each result on the page gets up to two snippets from its best-matching pages, in page order: about 110 characters before and 150 after the first match, cut at word boundaries, escaped, with the search words in `<mark>`. Titles are highlighted the same way. PDF page labels link to the file with `#page=N` in the same tab; sheet names and "In the document" (Word) are plain text.
- The REST endpoint `glover/v1/documents` returns the same markup, plus the effective `sort`.

### Staff tools

- **Review details:** a Check text button (approvers), and on published documents the text search status, its note and a Re-index text button.
- **Check text** (`admin.php?page=glover-document-text-check&post=ID`, approvers only): the file's text page by page, read on the spot and never stored, with non-town email addresses and excluded words marked "(email address)" or "(excluded word)", a list of everything flagged, a warning when the file has almost no text, and an error when a PDF has redaction marks that were never applied. Approve and Return buttons for pending documents. Optional: approving doesn't require it.
- **Settings > Search:** status counts, linked to list views (`glover_view=search_<status>`), and Re-index all.
- **Saving the Search settings** compares a signature of the settings with option `glover_document_search_signature`; when it changes, documents whose stored hash doesn't match are queued and processed by `glover_document_search_batch` 20 at a time, every 30 seconds, until none are left.
- **WP-CLI:** `wp glover documents index` (new or changed files), `--all` (everything), `--post=<id>`; `wp glover documents status`.

### Lifecycle

| Event | What happens |
| --- | --- |
| `document_published` | Queue |
| `document_replaced` | Delete rows, queue the new file |
| `document_unpublished` | Delete rows, clear status |
| Trash or permanent delete | Delete rows, clear status |
| Exclusion turned on or off | Delete rows and mark Excluded, or queue |
| File changed on a published document in wp-admin | Queue |
| Search settings changed | Queue every document whose hash no longer matches |

### Bundled PDF reader

`includes/vendor/smalot-pdfparser/` holds an unmodified copy of smalot/pdfparser 2.13.0 (LGPL-3.0), loaded by a small autoloader, with no Composer (decided 2026-10-20). It is used only when `pdftotext` isn't found at `/usr/bin`, `/usr/local/bin` or `/opt/homebrew/bin` (filter `glover_document_search/pdftotext_paths`) or PHP can't run programs. Files over 30 MB are not read by it. The note on each document says which reader was used, and a host move to one with `pdftotext` should be followed by `wp glover documents index --all`.

## Pages (database content, recreate on production)

- Submit a Document: template `template-document-submission.php`.
- Request an Accessible Copy: template `template-accessible-copy-request.php`.
- Document Submission Requirements: an ordinary page selected in settings, holding the guide text.

## File map

- `acf-json/post_type_6ad6258d36975.json`, `acf-json/group_6a7b16a4deb17.json`, `acf-json/taxonomy_document_category.json`, `acf-json/taxonomy_document_subject.json`, `acf-json/taxonomy_document_board.json`, `acf-json/group_glover_document_library_settings.json`, `acf-json/group_glover_document_submitter.json`, `acf-json/post_type_glover_access_copy_request.json`.
- `includes/features/document-library/document-library.php`: coordinator, required from `functions.php`.
- `document-library-config.php`: constants, settings access, permissions, role install, options page, audit log.
- `document-library-boards.php`: board taxonomy sync, board choices and validation.
- `document-library-storage.php`: private folder, moving files on publish and unpublish, permission-checked file streaming, file details.
- `document-library-integrations.php`: Google extension points and backup status.
- `document-library-drive.php`: Google Drive backup (folders, resumable upload, retries, failure notice, Retry button).
- `document-library-notifications.php`: emails.
- `document-library-lifecycle.php`: publishing guard, publish and unpublish handling, replacement merge, duplicate detection.
- `document-library-submission.php`: submission form, title and file field adjustments, replaces choices, validation, completing a submission.
- `document-library-review-screen.php` and `assets/review-admin.css`: the Document Library > Documents screen, which replaces WordPress's All Documents list in the menu (Pending, Approved and Returned tabs; replacements grouped and compared). The classic list stays reachable from a link on the Approved tab for bulk actions, drafts and the trash.
- `document-library-review.php`: list columns, Approve and Return, Published without review view, pending count, Review details box.
- `document-library-query.php`: filter parsing (including the Best match default sort), query building, facet counts, library URLs, REST endpoint `glover/v1/documents`.
- `document-library-search-extract.php`: reading PDF, Word and Excel text; the smalot autoloader; unapplied-redaction detection.
- `document-library-search-index.php`: the index table, scrubbing, the background jobs and batch runner, lifecycle hooks, status counts.
- `document-library-search-query.php`: title-or-text matching, Best match ranking, highlighting and snippets.
- `document-library-search-admin.php`: Review details lines, Re-index, Check text, the settings status message, list views, the exclusion field's permissions, and the `wp glover documents` command.
- `includes/vendor/smalot-pdfparser/`: the bundled PDF reader, with its license and a README recording the version and checksum.
- `document-library-public.php`: asset loading, download links, dates, board feed query, help contact.
- `document-library-requests.php`: accessible-copy request handler.
- `assets/library.js` (live filtering, year slider, topics and filters toggles), `assets/dialogs.js` (help and requirements dialogs), `assets/submission.js` (minutes status toggle). These load directly from the feature folder; styles do not (see Styles).
- `archive-document-library.php`, `single-document-library.php`, `single-department-board.php`, `template-document-submission.php`, `template-accessible-copy-request.php`.
- `components/document-library/`: `filters`, `summary`, `active-filters`, `results`, `help`, `requirements`, `latest-documents`.
- `components/common/page-banner.php`: the site-wide page banner (breadcrumb, title, optional action and note) used by every library template.

## Styles

All styles are Sass in `src/styles/`, compiled by Parcel into `dist/styles/app.css`, which `functions.php` loads on every page. The library has no stylesheet of its own.

- `base/_colors.scss`: the color palette as Sass variables and `--color-*` custom properties. Rules use the custom properties. `--color-highlight` marks search matches; `--color-line-subtle` is the faint divider above snippets on stacked cards.
- `base/_typography.scss`: typefaces, h1 to h6 sizes, body text, the `.rich-text` wrapper for editor content, and the `eyebrow` mixin.
- `base/_core.scss`: page background, links, focus ring, `.container`, `.content-narrow`, `.visually-hidden`, `.skip-link`, reduced motion.
- `components/`: `_page-banner`, `_breadcrumb`, `_button`, `_chip`, `_dialog`, `_form`, `_badge`, `_pagination`, `_document-feed`. Shared across pages.
- `pages/`: one file per page for styles unique to it: `_documents` (the library), `_document` (single document), `_submit-document`, `_department-board`. The request page needs nothing beyond components.

Build the CSS alone with `npx parcel build src/styles/app.scss --dist-dir dist/styles` from the theme folder. A full `npm install` still needs the licensed GSAP tarball (see CLAUDE.md).

## Dependencies

- WordPress and ACF Pro (already licensed), with Local JSON loading from the theme.
- The theme's Parcel and Sass build for styles. The library's scripts load directly from the feature folder.
- MySQL 5.6 or later with InnoDB FULLTEXT (local: MySQL 8.0). PHP `zip`, `xml`, `mbstring`, `iconv` and `zlib`.
- Bundled smalot/pdfparser 2.13.0. Optional and preferred on the host: poppler's `pdftotext`, with PHP allowed to run it.
- Planned, no new cost: Google Workspace (sign-in, Shared Drive, email), a town-owned Google Cloud project, and Cloudflare Turnstile.

## Accessibility considerations

The public library, board feeds, document pages, signed-out forms, both dialogs, and every document posted from now on must meet WCAG 2.1 AA by 2028-04-26. See [the accessibility requirements](../requirements/accessibility.md).

- Filters are a plain GET form and work without JavaScript. Live updates announce the results count in a polite live region, keep focus on the changed control, and wait for a pause in typing. After a chip or pagination link, focus moves to the results count.
- Year range: two labeled dropdowns, plus a slider built from two native range inputs labeled "From year" and "To year" that can't pass each other.
- Topic chips are real checkboxes. Active filters are links, so they work without JavaScript.
- Results are a real table with explicit roles kept for the stacked phone layout. Each result is its own `<tbody>`. The column headings are visually hidden at every width but stay for screen readers, and each date carries a visible "Effective Date" label (hidden from screen readers, which hear the "Effective date" heading). Each Download link includes the document title, format and size for screen readers.
- Search matches are `<mark>`, bold as well as colored (`--color-highlight`, 10:1 with ink), so they don't rely on color. Most screen readers don't announce `<mark>`; the snippet text and its "Page 4:" lead-in carry the meaning.
- Snippets appear twice in the markup (their own row on the full table, inside the Title cell on stacked cards); the hidden copy is `display: none`, so it is read once. Page links have unique names, for example "Page 4 of Select Board Minutes (PDF)". The live region still announces only the count.
- Dialogs use the native `<dialog>` element. Without JavaScript they are ordinary page sections reached by their links.
- The request form has visible labels, `autocomplete` on personal fields, and errors announced with `role="alert"` and focus.
- Draft minutes are labeled with text, not color.
- Verified in the browser on 2026-10-19: keyboard focus handling for filters, chips and both dialogs; no horizontal scroll at 375px. Not yet verified: a full screen-reader pass, and the ACF front-end form (including its date picker) with a keyboard and screen reader.

## Smoke-test checklist

1. Synchronize the changed ACF definitions: Document Fields, Document Library Settings, Document Library Submitter, and the Accessible Copy Requests post type.
2. In Document Library > Settings, confirm the Approver is set and the requirements page is selected.
3. Edit a user, give them the Document Submitter role, assign one board, and confirm a non-admin can't see these fields on their own profile.
4. Sign in as that user and open Submit a Document. Confirm only their board is listed, Minutes status appears only for Minutes, and the requirements button opens the guide in a dialog that closes with Escape.
5. Submit a PDF. Confirm the thank-you message, a Pending document in wp-admin with the pending count, and that the file's direct uploads URL returns 403.
6. As the approver, use the File link to preview, then Return with a reason. Confirm it becomes Draft and the reason shows in Review details.
7. Submit again and Approve. Confirm the document is published, its file downloads, and its Review details show "Drive backup: Not configured".
8. Submit approved minutes that replace the draft. After approval, confirm the original address shows the new file and title, and Previous versions lists the old file.
9. Turn on Auto-approve for the submitter and confirm a new submission publishes immediately and appears under "Published without review".
10. On `/documents/`, use search, board, years, categories and topics. Confirm results update without reloading, the URL changes, Back restores the previous view, and the count is announced.
11. Disable JavaScript and confirm the same filters work through Apply filters.
12. At 375px wide, confirm the Filters button, the stacked result cards, and no sideways scrolling.
13. Open a board page and confirm Latest documents and the "See all" link.
14. Submit the accessible-copy request form, and confirm the saved request under Document Library and the thank-you message.
15. Publish a text PDF. Within a minute (or after `wp cron event run --due-now`), confirm Review details shows "Text search: Searchable", then search for a word from its second page and confirm the snippet, its page link and the highlighted word.
16. Confirm the Sort menu switches to Best match while searching and back to Most recent when the box is cleared, and that a title match ranks above a text match.
17. Add a phrase to Settings > Search > Excluded words and phrases and save. Confirm the counts show documents queued, then that the phrase can't be found or seen in snippets.
18. On a pending document, open Check text. Confirm the text shows page by page, and non-town email addresses and excluded words are flagged.
19. Turn on "Exclude this file's text from search" on a document. Confirm it is still found by title but not by its text, and that a non-approver can't see the switch.
20. Replace, unpublish and trash documents, and confirm their old text can't be found.

## Verification approach

- Parse every ACF JSON file, check field keys for duplicates, and verify every conditional-logic reference resolves.
- `php -l` every feature, template and component file; `node --check` every feature script.
- End-to-end WP-CLI script covering private storage and HTTP blocking, approval, slug assignment, replacement merge, duplicate detection, the publishing guard, submitter board limits, search and facets, and the board feed. 22 of 22 checks passed on 2026-10-19 with all test content removed afterward.
- HTTP checks of every template and the REST endpoint, and an HTTP submission of the request form.
- Browser checks of live filtering, focus, dialogs and the phone layout.
- Full-text search: a WP-CLI end-to-end script that builds PDF, Word and Excel files, then checks private and pending files are never indexed, page rows and statuses, email and excluded-term removal (town email kept), page-ordered snippets with highlighting, phrases (straight and curly quotes), words across pages and title plus text, operator characters, No text for a blank PDF, title-first ranking, facet totals, the results markup and REST response, exclusion on and off, a settings change re-indexing through the batch runner, replacement, unpublish, trash, and redaction detection. 48 of 48 checks passed on 2026-10-20 with all test content removed and nothing sent to Drive or email. Browser checks the same day: live search on the real test minutes (Best match switching, a chosen sort kept, clearing), the 1400px table and 375px cards with no sideways scrolling, Review details, Check text and the Search settings tab.

## Known limitations and risks

- **The theme runs in block-theme mode** because `templates/index.html` exists, so any URL without a specific PHP template renders that blank file. The library's templates are specific enough to win. This affects the rest of the site and should be fixed separately.
- `header.php` still lacks a skip link and landmarks, so library pages can't fully pass WCAG 2.4.1 until the site header is built. Library templates already provide `<main id="main-content">` as the skip target.
- Email isn't delivered until the site's mail is configured. Requests are still saved in wp-admin.
- The private folder is protected by `.htaccess`, which only works on Apache. An nginx host must deny `uploads/glover-private-documents/` in its server config.
- `wp_publish_post()` bypasses the publishing guard by design. Approve, Auto-approve and admin publishing are the only callers.
- ACF prints the file field's `accept` attribute as "pdf, docx, xlsx", which browsers don't use to filter the file picker. Type checks still happen on the server.
- Colors and type follow the Figma mockup. They live in `base/_colors.scss` and `base/_typography.scss`, so the rest of the site inherits them, and they can be changed in one place when the full design system arrives.
- Results show as stacked cards below 1200px wide, including beside the filters at 992 to 1199px, because the full table needs about 760px for its columns.
- Terms, pages and settings are database content and must be recreated or migrated on the production site.
- Hosting is undecided. Scheduled jobs for Drive retries, upload limits and virus scanning all depend on it.
- **Text search protects search, not files.** Removed email addresses and excluded words are still in the downloadable file. Text hidden under drawn black boxes is still in the file too; Check text is how the approver catches it.
- Redaction-mark detection reads the raw PDF bytes, so marks stored inside compressed object streams are missed.
- Scanned PDFs are searchable by title only. How many legacy PDFs are scans is still unknown.
- InnoDB ignores words under three characters in the text (`innodb_ft_min_token_size`), so "R1" or "VT" only match titles. MariaDB, if the host uses it, should be re-tested.
- Word files show "In the document" rather than page numbers, because Word pages depend on how the file is displayed.
- The bundled PDF reader is slower and less accurate than `pdftotext` with unusual fonts and columns, and skips files over 30 MB.
- `#page=` links are a browser convention; phones often open the PDF at page 1 or download it.
- Indexing runs on WP-Cron, which needs site visits; production should run a real cron, as for Drive backup.
- Search help copy now mentions searching inside documents, quotes for phrases, scans, short words and email addresses; Jenny's final copy should keep those points.

## Open questions

- Where policies, notices and flyers, and budgets belong, since the current categories have no term for them.
- The starting topic list (Recreation and Roadwork exist so far).
- The backup approver at launch, and who besides Jenny and Olivia gets Auto-approve.
- Final search help copy (Jenny is writing it).
- Google Workspace admin access (Jenny is requesting it), whether the plan allows SMTP relay and includes Shared Drives, and who controls DNS for townofglover.com.
- How many submitters are outside townofglover.com.
- Whether the town (and Olivia) is comfortable with names and street addresses in old minutes becoming easy to find once legacy files are migrated.
- Whether the chosen host can run `pdftotext`.

## Material change history

- 2026-10-20: Renamed Review to Documents and made it the only document list in the menu (WordPress's All Documents item is hidden; the classic list is linked from the Approved tab). Review tables now use plain alternating white and light gray rows; only the Changed and New file tags are highlighted, yellow on yellow. Comparison columns are labeled Currently published and Proposed replacement.
- 2026-10-20: Added Document Library > Review, now the menu's first item, with the pending count. **Pending** groups each replacement with the published document it replaces in a side-by-side comparison (title, document date, minutes status, boards, category, topics, file), labels changed details with the word "Changed" plus a highlight, warns when more than one replacement was submitted for the same document, then lists new submissions oldest first. **Approved** lists published documents, newest document date first, with title search, backup status and a "Replaced N times" tag. **Returned** lists returned documents with the reason and date. Approve and Return started from this screen come back to it.
- 2026-10-20: Full-text search. Added the `glover_document_text` table, background indexing of published PDF, Word and Excel files (pdftotext or the bundled smalot/pdfparser 2.13.0), removal of non-town email addresses and excluded words, the Search settings tab, the approver-only "Exclude from text search" field, Check text, Re-index, status list views, and `wp glover documents`. Search now matches titles or text with Best match ranking and page-numbered snippets. Restyled result rows to the approved mock-up: larger forest-green titles, outlined topic pills, an "Effective Date" label, visually hidden column headings, an outlined Download button, and snippet rows. Updated the help copy, the search box label and placeholder, and `library.js` for the Best match default.
- 2026-10-20: Phases 2 and 3: sign-in links now go to `/sign-in/` (Google or emailed code), and approved documents back up to the Shared Drive through `document-library-drive.php`. Added a "failed backups" list view, the backup note and a Retry button in Review details, and a helper so empty meta lists aren't counted as one item.
- 2026-10-19: Moved all library styles into the theme's Sass build: shared colors, typography and core styles in `base/`, reusable pieces (page banner, breadcrumb, buttons, chips, dialog, forms, badges, pagination, document feed) in `components/`, and page-specific styles in `pages/`. Replaced the hand-written banners with `components/common/page-banner.php`, renamed `glover-*` classes to component and page names, and removed `assets/library.css`.
- 2026-10-19: Phase 1. Built the field contract, settings screen, submitter role and profile fields, the ACF front-end submission form with private pending storage, the review queue with Approve and Return, replacement merging with stable addresses, duplicate detection, email notices, the public library with live filtering and facets, single document and board pages, the search help and requirements dialogs, and the accessible-copy request form with its private post type. Added extension points for Google sign-in and Drive backup without building either.
- 2026-10-19: Created this spec from the approved proposal. Phase 0: pointed Document Fields at `document-library`, renamed `document_cateogry` to `document_category`, made both taxonomy fields save real term relationships without in-field term creation, defined the `document-category`, `document-subject`, and hidden `document-board` taxonomies, gave the post type `/documents/` URLs and Document labels, and removed document uploads from Meetings.
