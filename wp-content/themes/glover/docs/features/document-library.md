# Document Library

## Status

Phase 1 (the core library) is built and verified locally. It includes the field contract, settings screen, submitter permissions, submission form with private pending storage, review queue, public library with live filtering, board feeds, and the accessible-copy request form. Documents have no pages of their own (see Post type).

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
- The public library at `/documents/`, with live filtering and a search help modal. Each document has a permanent address that opens its current file; there are no individual document pages.
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
3. If the submitter has Auto-approve, the document publishes immediately. Otherwise every approver gets the review summary email (every document waiting, newest first), and the Document Library menu shows a pending count.
4. The approver previews the file through a permission-checked link, then chooses Approve or Return (with a reason that is emailed to the submitter).
5. On publish, the file moves to `uploads/documents/<year>/`, a slug is set, the submitter is emailed, and `glover_document_library/document_published` fires. That hook is where the Drive backup will attach.
6. If the document replaces an earlier one, its file, date, minutes status, boards, category and topics move into the original document. The original keeps its address, the old file is kept privately in its version history, and the replacement submission is retired to the trash.
7. Replacements are reviewed in a compare dialog on Document Library > Documents (see Replacement review below). For each detail that changes, the approver chooses Keep current or Use proposed and can edit the title. Kept details are copied from the published document onto the replacement just before it is published, so step 6 carries them over unchanged. When several replacements are pending for one document, approving one returns the others with a required reason.

### Replacement review

- The Pending tab lists one row per published document with pending replacements: its title, tags naming what changes (or "N replacements" when there are several), submitters and dates, and a Compare button. A replacement where only the file changes also gets an Approve file button, which approves it without opening the comparison.
- Compare opens a native `<dialog>`. Without JavaScript, the same link opens the comparison as a page (`&compare=<published ID>`).
- The comparison shows the published file and the proposed one (or, with several, the published file above a card per replacement to choose from), then the details that change with Keep current / Use proposed for each, an Edit title control on the title row, and the details that stay the same behind a disclosure. The footer sentence restates what approving will do and updates as choices change.
- One form posts to `admin-post.php?action=glover_document_review_replacement` with `original`, `replacement`, `keep[<replacement>][<detail>]` (`current` or `proposed`), `title[<replacement>]`, `others_reason` and `decision` (`approve` or `return`). Return sends every pending replacement for that document back with the one reason. The handler re-reads the pending group, so a replacement that was reviewed elsewhere can't be approved twice.
- Each file in the comparison has Preview and Check text. Preview shows the file in place of the comparison, the same way as the new-document preview (PDFs in a frame, Word and Excel as their text). Check text links show the file's text in place of the comparison, inside the same dialog (no second dialog). Back to comparison or Escape returns to it. The report comes from `admin-ajax.php?action=glover_document_check_text`, which renders the same `glover_document_search_render_check_body()` as the Check text page.
- New documents: the title is plain text. The row's actions are Edit | Return, Review; Review opens a preview dialog with Open in new tab, Edit | Return, Approve (a document with no file gets Approve in place of Review). PDFs show in a frame; Word and Excel files, which browsers can't display, show their text from the Check text report. The File column is plain text. Without JavaScript, Review opens the file in a new tab, and Approve is on the classic list.
- The accent color follows the user's admin color scheme: the script copies WordPress's own primary button color into `--glover-accent`.

## Data and field contracts

### Post type

- Post type: `document-library` (`acf-json/post_type_6ad6258d36975.json`).
- Public archive: `/documents/`. Each document also has a permanent address, `/documents/<slug>/`, which **redirects (302) straight to its current file**. There is no page per document: a document's settings and tags appear in the library listing and on the admin Documents screen. The slug is set once on first publish and never changes on replacement, so shared links always open the newest file. Unpublished documents redirect to the permission-checked preview for reviewers and their submitter, and are a 404 to everyone else; a document without a file is a 404.
- Library titles open the document, and Download saves it (`download` attribute). Both links' accessible names include the format and size.
- Admin labels use "Document" and "Documents". The menu reads "Document Library".
- Only approvers, administrators, and Auto-approve submissions can publish. Anyone else's publish attempt is saved as Pending (`wp_insert_post_data` guard).

### Field group: Document Fields

`acf-json/group_6a7b16a4deb17.json`, located on `document-library`. Meta names are contracts.

| Meta name | Type | Required | Notes |
| --- | --- | --- | --- |
| `document_file` | File (pdf, docx, xlsx, jpg, jpeg), returns ID | Yes | Size cap from settings. WordPress rejects files whose content doesn't match their extension |
| `document_boards` | ACF Select, multiple, stylized UI (type to search), board post IDs | Yes | Public form lists only the submitter's boards, and pre-fills the board when there is only one. Mirrored into `document-board` on save |
| `document_category` | Taxonomy radio (`document-category`) | Yes | Saves real term relationships |
| `document_topics` | Taxonomy checkboxes (`document-subject`) | No | Saves real term relationships. No in-field term creation |
| `document_date` | Date picker, stored `Ymd` | Yes | Drives Year, sorting and display. Formatted in the site time zone |
| `document_minutes_status` | `draft` / `approved` | When the category is Minutes | Placed right after Category. ACF conditional logic ("Category: Term is equal to Minutes", term ID 31) shows it only for Minutes, and ACF requires it whenever it shows. The rule stores the term ID, so recreate it in the field group if the Minutes term is ever recreated. |
| `document_replaces` | ACF Post Object (published documents, returns the ID) | No | Shown only once a board is chosen (ACF conditional logic: Board has any value). Lists only documents from the chosen boards, limited to the boards the person may post for: `assets/replaces-boards.js` adds the chosen boards to ACF's search through its `select2_ajax_data` hook (ACF can't filter by another field's value), and `acf/fields/post_object/query` applies them. The choice is checked again on save |
| `document_accessibility_confirmed` | True/false | Yes | "I verify that this meets accessibility standards for documents." plus a link, set in the ACF field, to the requirements guide: on the public form it opens the guide dialog; in wp-admin (no dialog) a load_field filter points it at the requirements page in a new tab |
| `document_legal_confirmed` | True/false | Yes | |
| `document_reviewer_note` | Textarea | No | Shown to reviewers in the Review dialog (new documents) and the compare dialog (replacements), not in the row |
| `document_exclude_from_text_search` | True/false | No | Approvers and administrators only (hidden from everyone else, and protected on save). Keeps the file's text out of search; the title is still searched. Not on the submission form |

System meta, written by code and never edited by hand:

| Meta key | Purpose |
| --- | --- |
| `_glover_submitted_by` | User ID of the submitter |
| `_glover_auto_published` | 1 when published without review |
| `_glover_possible_duplicates` | IDs with the same category, date and a shared board |
| `_glover_returned_reason` | The approver's reason, when returned |
| `_glover_previous_files` | Version history: the previous attachment, when it was replaced, the replacing submission, the new attachment, who approved it, and a snapshot of the previous title, date, minutes status, boards, category and topics (used by Undo replacement) |
| `_glover_published_at` | When the document was last approved and published (used by Move back to Pending). Older documents fall back to the activity log |
| `_glover_merged_into` | On a retired replacement, the document it updated |
| `_glover_audit_log` | Time, user, action, note for every step |
| `_glover_drive_backup_status`, `_glover_drive_backup_note`, `_glover_drive_file_id` | Drive backup tracking. The ID, never a URL |
| `_glover_text_index_status` | Text search: `queued`, `indexed`, `no_text`, `failed`, `excluded`. Absent when not published |
| `_glover_text_index_note` | How the file was read and how many pages, or the error |
| `_glover_text_index_hash` | SHA-1 of the indexed file plus the settings signature, so unchanged files are skipped and a settings change re-indexes |

### Taxonomies

All three are flat, attached only to `document-library`, visible in REST, and have no public archives or rewrites. Their native meta boxes are disabled so the ACF fields manage assignment.

- `document-category`: the formal grouping, one per document. The terms in WordPress are the source of truth. As of 2026-10-19 the local site has Agendas, Minutes, Ordinances, Bylaws, Reports, Forms, Plans & Studies, and Maps. Minutes status depends on the `minutes` slug.
- `document-subject` ("Topics"): informal tags shown as chips and in the active-filter rail. A controlled list that approvers manage under Topics.
- `document-board`: hidden mirror of Departments & Boards with no admin UI. Each term's slug is `board-<post ID>`, so renaming a board never breaks it. Terms are created and renamed when a board is saved, and deleted when it is trashed.

### Settings

Document Library > Settings (`acf-json/group_glover_document_library_settings.json`, options page registered in PHP), admins only:

| Setting | Meta name (options) | Notes |
| --- | --- | --- |
| Approvers | `document_library_approver` (ACF User, multiple) | Required. Everyone listed can approve and gets the review emails |
| Upload size cap (MB) | `document_library_upload_cap` | Blank means the host's limit |
| Help contact name, phone, email | `document_library_help_contact_*` | Shown in the help dialog and empty results. The email also receives accessible-copy requests |
| Requirements guide page | `document_library_requirements_page` | The page the requirements dialog shows. The clerk keeps it current |
| Turnstile site and secret keys | `document_library_turnstile_*` | Optional bot check for the request form |
| Remove email addresses (Search tab) | `document_library_search_remove_emails` | On unless turned off. Addresses at the town's domain are always kept |
| Excluded words and phrases (Search tab) | `document_library_search_excluded_terms` | Commas or new lines. Whole words, any capitals, entries under three characters ignored. Never shown publicly |
| Search status (Search tab) | message field, no value | Counts by status linking to filtered lists, which PDF reader is in use, and Re-index all |

The fields sit under General, Uploaders and Search tabs.

### Submitters

A submitter is a WordPress user, so sign-in only has to sign people in as users. Who may submit is set in **Settings > Uploaders** (`document_library_uploaders`, an ACF repeater on the settings page, which only administrators can open). Each row has:

- `user`: the person. They need a WordPress account first; the Document Submitter role (`read` only, in practice) is the right one for people who only submit.
- `all_boards`: a toggle that lets them post for every board. When it is on, ACF conditional logic hides `boards`.
- `boards`: the boards they may post for (required, one or more, when All boards is off).
- `auto_approve`: a toggle that publishes their documents without review.

Approvers and administrators can always submit, for every board, without being listed. Being listed is what grants submission; the `submit_glover_documents` capability on the role no longer does.

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

- `acf-json/post_type_6ad6258d36975.json`, `acf-json/group_6a7b16a4deb17.json`, `acf-json/taxonomy_document_category.json`, `acf-json/taxonomy_document_subject.json`, `acf-json/taxonomy_document_board.json`, `acf-json/group_glover_document_library_settings.json`, `acf-json/post_type_glover_access_copy_request.json`.
- `includes/features/document-library/document-library.php`: coordinator, required from `functions.php`.
- `document-library-config.php`: constants, settings access, permissions, role install, options page, audit log.
- `document-library-boards.php`: board taxonomy sync, board choices and validation.
- `document-library-storage.php`: private folder, moving files on publish and unpublish, permission-checked file streaming, file details.
- `document-library-integrations.php`: Google extension points and backup status.
- `document-library-drive.php`: Google Drive backup (folders, resumable upload, retries, failure notice, Retry button).
- `document-library-notifications.php`: emails.
- `document-library-lifecycle.php`: publishing guard, publish and unpublish handling, replacement merge, duplicate detection.
- `document-library-edit-screen.php`: a document's edit screen: the Document box (replaces WordPress's Publish box; Save, Return, Approve, Update and the undo options by state), the Details and History boxes, the status tag and Back to Documents link, the Link row with Copy link, and save notices.
- `document-library-submission.php`: submission form, title and file field adjustments, replaces search limits and check, completing a submission.
- `document-library-undo.php` and `assets/undo-confirm.js`: Move back to Pending and Undo replacement, within 7 days of approval, confirmed in an admin-styled dialog.
- `document-library-trash.php`: the Trash and Drafts tabs, Restore and Delete permanently, the Approved tab's filtered views, and the redirect from WordPress's document list.
- `document-library-review-screen.php`, `assets/review-admin.css` and `assets/review-admin.js`: the Document Library > Documents screen, which replaces WordPress's All Documents list in the menu (Pending, Approved and Returned tabs).
- `document-library-review-compare.php`: the replacement queue, the compare dialog and page, detail comparison, and the approve-or-return handler. `assets/review-admin.js` opens the dialog and drives its choices.
- `document-library-review.php`: list columns, Approve and Return (`glover_document_library_return_document()` is shared with the compare dialog), Published without review view, pending count, Review details box.
- `document-library-query.php`: filter parsing (including the Best match default sort), query building, facet counts, library URLs, REST endpoint `glover/v1/documents`.
- `document-library-search-extract.php`: reading PDF, Word and Excel text; the smalot autoloader; unapplied-redaction detection.
- `document-library-search-index.php`: the index table, scrubbing, the background jobs and batch runner, lifecycle hooks, status counts.
- `document-library-search-query.php`: title-or-text matching, Best match ranking, highlighting and snippets.
- `document-library-search-admin.php`: Review details lines, Re-index, Check text, the settings status message, list views, the exclusion field's permissions, and the `wp glover documents` command.
- `includes/vendor/smalot-pdfparser/`: the bundled PDF reader, with its license and a README recording the version and checksum.
- `document-library-public.php`: asset loading, download links, dates, board feed query, help contact.
- `document-library-requests.php`: accessible-copy request handler.
- `assets/library.js` (live filtering, year slider, topics and filters toggles), `assets/dialogs.js` (help and requirements dialogs), `assets/replaces-boards.js` (sends the chosen boards to the replaces search). These load directly from the feature folder; styles do not (see Styles).
- `archive-document-library.php`, `single-department-board.php`, `template-document-submission.php`, `template-accessible-copy-request.php`.
- `components/document-library/`: `filters`, `summary`, `active-filters`, `results`, `help`, `requirements`, `latest-documents`.
- `components/common/page-banner.php`: the site-wide page banner (breadcrumb, title, optional action and note) used by every library template.

## Styles

All styles are Sass in `src/styles/`, compiled by Parcel into `dist/styles/app.css`, which `functions.php` loads on every page. The library has no stylesheet of its own.

- `base/_colors.scss`: the color palette as Sass variables and `--color-*` custom properties. Rules use the custom properties. `--color-highlight` marks search matches; `--color-line-subtle` is the faint divider above snippets on stacked cards.
- `base/_typography.scss`: typefaces, h1 to h6 sizes, body text, the `.rich-text` wrapper for editor content, and the `eyebrow` mixin.
- `base/_core.scss`: page background, links, focus ring, `.container`, `.content-narrow`, `.visually-hidden`, `.skip-link`, reduced motion.
- `components/`: `_page-banner`, `_breadcrumb`, `_button`, `_chip`, `_dialog`, `_form`, `_badge`, `_pagination`, `_document-feed`. Shared across pages.
- `pages/`: one file per page for styles unique to it: `_documents` (the library), `_submit-document`, `_department-board`. The request page needs nothing beyond components.

Build with `npm run build` from the theme folder.

## Dependencies

- WordPress and ACF Pro (already licensed), with Local JSON loading from the theme.
- The theme's Parcel and Sass build for styles. The library's scripts load directly from the feature folder.
- MySQL 5.6 or later with InnoDB FULLTEXT (local: MySQL 8.0). PHP `zip`, `xml`, `mbstring`, `iconv` and `zlib`.
- Bundled smalot/pdfparser 2.13.0. Optional and preferred on the host: poppler's `pdftotext`, with PHP allowed to run it.
- Planned, no new cost: Google Workspace (sign-in, Shared Drive, email), a town-owned Google Cloud project, and Cloudflare Turnstile.

## Accessibility considerations

The public library, board feeds, signed-out forms, both dialogs, and every document posted from now on must meet WCAG 2.1 AA by 2028-04-26. See [the accessibility requirements](../requirements/accessibility.md).

- Filters are a plain GET form and work without JavaScript. Live updates announce the results count in a polite live region, keep focus on the changed control, and wait for a pause in typing. After a chip or pagination link, focus moves to the results count.
- Year range: two labeled dropdowns, plus a slider built from two native range inputs labeled "From year" and "To year" that can't pass each other.
- Topic chips are real checkboxes. Active filters are links, so they work without JavaScript.
- Results are a real table with explicit roles kept for the stacked phone layout. Each result is its own `<tbody>`. The column headings are visually hidden at every width but stay for screen readers, and each date carries a visible "Effective Date" label (hidden from screen readers, which hear the "Effective date" heading). Each Download link includes the document title, format and size for screen readers.
- Search matches are `<mark>`, bold as well as colored (`--color-highlight`, 10:1 with ink), so they don't rely on color. Most screen readers don't announce `<mark>`; the snippet text and its "Page 4:" lead-in carry the meaning.
- Snippets appear twice in the markup (their own row on the full table, inside the Title cell on stacked cards); the hidden copy is `display: none`, so it is read once. Page links have unique names, for example "Page 4 of Select Board Minutes (PDF)". The live region still announces only the count.
- Dialogs use the native `<dialog>` element. Without JavaScript they are ordinary page sections reached by their links.
- The request form has visible labels, `autocomplete` on personal fields, and errors announced with `role="alert"` and focus.
- Draft minutes are labeled with text, not color.
- The replacement compare dialog is part of the staff admin, outside the public-site scope, but follows the same practice: a native `<dialog>` with a labelled heading that takes focus, Escape and Close, focus returned to Compare, real radio buttons for every choice (drawn as two-part buttons with a visible focus ring), "Changed" and "Edited" written as words, and the footer sentence in a polite live region.
- Verified in the browser on 2026-10-19: keyboard focus handling for filters, chips and both dialogs; no horizontal scroll at 375px. Not yet verified: a full screen-reader pass, and the ACF front-end form (including its date picker) with a keyboard and screen reader.

## Smoke-test checklist

1. Synchronize the changed ACF definitions: Document Fields, Document Library Settings, Document Library Submitter, and the Accessible Copy Requests post type.
2. In Document Library > Settings, confirm at least one approver is set and the requirements page is selected.
3. Create a user with the Document Submitter role, add them in Settings > Uploaders with one board, and confirm they can open the submission form.
4. Sign in as that user and open Submit a Document. Confirm only their board is listed, Minutes status appears only for Minutes and is required, Replace an earlier version of a document narrows as you type, and the requirements button opens the guide in a dialog that closes with Escape.
5. Submit a PDF. Confirm the thank-you message, a Pending document in wp-admin with the pending count, and that the file's direct uploads URL returns 403.
6. As the approver, use the File link to preview, then Return with a reason. Confirm it becomes Draft and the reason shows in Review details.
7. Submit again and Approve. Confirm the document is published, its file downloads, and its Review details show "Drive backup: Not configured".
8. Submit approved minutes that replace the draft. On Documents > Pending, confirm one row with tags for what changes, then open Compare. Choose Keep current for one detail, confirm the footer sentence changes, and approve. Confirm the original address shows the new file, the kept detail is unchanged, the other details are updated, and Previous versions lists the old file.
9. Turn on Auto-approve for the submitter in Settings > Uploaders and confirm a new submission publishes immediately and appears under "Published without review".
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
21. Submit two replacements for the same published document. Confirm the row says "2 replacements", the dialog shows a card for each, choosing a card switches the details table and the Approve button's name, and approving without a reason for the other is refused. Approve one with a reason, and confirm the other is Returned with that reason and its submitter is emailed.
22. In the compare dialog, confirm Escape, Close and a click outside each close it and return focus to Compare, and that Return to submitter swaps in a required reason box. Disable JavaScript and confirm Compare opens the comparison as a page that still approves and returns.

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
- ACF prints the file field's `accept` attribute as "pdf, docx, xlsx, jpg, jpeg", which browsers don't use to filter the file picker. Type checks still happen on the server.
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
- Who the approvers are at launch, and who besides Jenny and Olivia gets Auto-approve.
- Final search help copy (Jenny is writing it).
- Google Workspace admin access (Jenny is requesting it), whether the plan allows SMTP relay and includes Shared Drives, and who controls DNS for townofglover.com.
- How many submitters are outside townofglover.com.
- Whether the town (and Olivia) is comfortable with names and street addresses in old minutes becoming easy to find once legacy files are migrated.
- Whether the chosen host can run `pdftotext`.

## Material change history

- 2026-10-21: The accessibility checkbox now reads "I verify that this meets accessibility standards for documents." followed by a "Review accessibility and legal requirements for uploaded documents" link that opens the same guide dialog as the button at the top of the form (the requirements page, in a new tab, on the edit screen).
- 2026-10-21: Document File now also accepts JPEG (ACF file-type setting: pdf, docx, xlsx, jpg, jpeg), with the help text "Accepted formats: PDF, Word (.docx), Excel (.xlsx), or JPEG." JPEGs are labelled JPEG, show as images in the preview dialogs, and are marked No text in text search (images have no text to read; they are found by title).
- 2026-10-21: Document field labels are now title case (Document File, Minutes Status, Date of the Document, Replace an Earlier Version of a Document, Public Record, Note to the Reviewer, Text Search, and the form's Document Title), the same on the submission form and the edit screen.
- 2026-10-21: Renamed the `document_replaces` field's label from "This replaces an earlier version" to "Replace an earlier version of a document".
- 2026-10-21: "This replaces an earlier version" now appears only after a board is chosen (ACF conditional logic) and lists only published documents from the chosen boards, for approvers and uploaders alike. ACF can't filter a Post Object by another field's value, so the small `assets/replaces-boards.js` passes the chosen boards through ACF's `select2_ajax_data` hook; the save check rejects a document from any other board.
- 2026-10-21: Restyled the edit screen's sidebar boxes from the approved "Sidebar boxes, restyled" mock-ups. The Document box leads with a status pill (Live, Pending review, Returned, Draft); an approved document adds Preview, which opens the live file in a new tab. Approved, Returned and Submitted show as labelled lines with WordPress dashicons, the returned reason is quoted under "Reason sent to <first name>", and the undo options sit in a shaded panel. Details shows tags, the submitter's note with their avatar, and label/value rows (Replaces, May duplicate, Drive backup, Text search) above the Check text, Re-index and Retry buttons. History lists previous versions with their file type and size, and the activity log as a timeline in plain wording (repeated published/unpublished entries are hidden). Colors come from WordPress's theme color variable and the named palette in `review-admin.css`.
- 2026-10-21: Curated the document edit screen (design: the "Glover Document Edit Screen" canvas). WordPress's Publish box is replaced by one **Document** box whose buttons follow the state: pending (Save changes | Return, Approve, where Approve saves first and then publishes), approved (Update, plus Move back to Pending and Undo replacement for 7 days), returned (the reason, Save changes) and draft (Save draft, Publish); Move to Trash in each. Review details is split into **Details** (tags, the submitter's note, Check text, Drive backup and text search status) and **History** (previous versions and activity, closed until opened). The heading shows a status tag and a Back to Documents link to the matching tab; the permalink row reads "Link" with Copy link and no slug editing; save notices use document wording. Removed the empty content editor (`editor` support, in the ACF post type). Undo buttons inside the edit form now point at forms printed outside it (nested forms are invalid HTML). Admin CSS now takes its accent from WordPress's `--wp-admin-theme-color` variables (WordPress 7's #3858e9 by default, not the older #2271b1), and the grays, red and notice colors WordPress has no variables for are named once at the top of `review-admin.css`.
- 2026-10-21: A document's edit screen now uses the same dialogs as the Documents screen. A **Review** button under Document file opens the preview dialog with the submitter's note, Return and Approve (a pending document; others get **Preview** with the file only). **Check text** in Review details opens the same dialog showing the file's text, and **Return** opens the Return dialog instead of its own page (both pages remain as fallbacks without JavaScript). Fixed the Forbidden error when clicking a pending document's file name: private files' direct addresses are blocked by design, so `wp_get_attachment_url` now returns the permission-checked stream link for them.
- 2026-10-21: Every Document Library email now uses one shared layout, `glover_email_render()` in the Glover Google Workspace must-use plugin (`mu-plugins/glover-google/email.php`, `templates/email.php`), matching the design canvas: the review summary (approvers), Published and Update published (submitter), Needs changes (submitter, with the reviewer's reason under "What to change" and a link to the requirements page), and Accessible copy requested (help contact, with Reply-To set to the requester). The sign-in code email uses it too. `glover_document_library_mail()` now takes layout arguments instead of text lines; the replacement email moved from `document-library-lifecycle.php` to `glover_document_library_notify_replaced()`.
- 2026-10-21: The approvers' email is now a formatted HTML summary of every document waiting for review, newest first, sent each time a document is submitted (design: the "Glover Review Email" canvas). Each entry links to the document and shows tags for Updated version, Draft minutes, Possible duplicate and Note from submitter; the button opens the Documents screen. The Gmail sender in the must-use plugin now sends HTML email with a plain-text copy (`multipart/alternative`).
- 2026-10-21: New documents on Pending no longer show the submitter's note in the row. A "Note from submitter" tag marks rows that have one, and the note itself (line breaks kept, scrolling when long) shows in the Review dialog above the file. Tags on the Pending and Approved tables now sit on their own line under the title, side by side and wrapping.
- 2026-10-20: Board is now an ACF multi-select with the stylized (searchable) UI instead of checkboxes, on both the public form and wp-admin. Submitters only see the boards they may post for; with one board it is already filled in. Saved values are unchanged (an array of board IDs).
- 2026-10-20: Added an **All boards** toggle to each Uploaders row. When on, the person may post for every board and ACF conditional logic hides Boards; when off, Boards is required. All-board access is always a deliberate choice rather than an empty field.
- 2026-10-20: Moved submitter settings from user profiles to a new **Uploaders** tab in Document Library > Settings: a repeater of person, boards and an Auto-approve toggle. The list now decides who may submit (approvers and administrators always can). Removed the profile field group (`group_glover_document_submitter.json`) and its saved values, and moved the one existing submitter into the list.
- 2026-10-20: Replaced the Approver and Backup approver settings with one **Approvers** setting that takes any number of people (the same ACF User field, now multiple; the field name `document_library_approver` is unchanged). Removed `document_library_backup_approver` and moved both saved people into the new list.
- 2026-10-20: The Returned tab has a red Trash button at the end of each row and a Trash all button at the top. Trash all confirms in the shared admin dialog; the row button trashes at once, since both can be restored from the Trash tab back to Returned. Handled by `glover_document_trash_returned` in `document-library-trash.php`.
- 2026-10-20: Cleaned up the document fields, using ACF's own features instead of custom code. Minutes status now follows Category and is shown and required by ACF conditional logic only for Minutes; removed `assets/submission.js` and the PHP minutes check. Removed Suggest a new topic everywhere (field, form, review flag, emails) and deleted its saved values. This replaces an earlier version is now an ACF Post Object field with type-to-search over all published documents (it was a select of the 50 most recent). The accessibility checkbox reads "This meets accessibility standards for documents." The submission page ends with a questions note listing the Town Clerk email (Help contact email setting) and the webmaster address (`GLOVER_GOOGLE_MAIL_FROM`).
- 2026-10-20: The Documents screen is now the only document list. WordPress's list (`edit.php?post_type=document-library`) redirects to the matching tab, keeping its status, search and view, including after trashing from the edit screen. Added a **Trash** tab (Restore puts a document back where it was without re-sending the "published" email; Delete permanently removes it and its files after a red-button confirmation in the admin dialog; Drive copies stay) and a **Drafts** tab that appears only when there are unreturned drafts. Merged replacements stay hidden in the trash because Undo replacement needs them. The "published without review", "Drive backup failed" and text-search status links now open filtered views of the Approved tab. Approved rows show Move back to Pending | View Edit; View opens the file in a new tab, and the hover link is gone.
- 2026-10-20: Removed individual document pages. `/documents/<slug>/` now redirects to the document's current file, keeping the stable address across replacements. Deleted `single-document-library.php` and `pages/_document.scss`. Library and board-feed titles open the file, with format and size in their accessible names, and Download saves it. The "Request an accessible copy" link that lived on the document page moved onto the library page. Admin View links now read "Open file".
- 2026-10-20: Added undo for mistaken approvals, available for 7 days. **Move back to Pending** takes a document off the public site (file made private, search text removed) and returns it to Pending. **Undo replacement** restores the previous file, title, date, minutes status, boards, category and topics; puts the replacement back in Pending, grouped with the original; keeps the address; points the Drive file ID back at the old file; and re-indexes search. Both are confirmed in a dialog built like the Documents screen's Return dialog, in WordPress admin styling with no public-site styles. Approved rows now end with an Actions column, styled like the Pending tab's buttons: any available undo, a separator, then Edit, which is always shown. The buttons stack on windows narrower than 1280px. Emails already sent are not recalled, Drive keeps every copy, and Undo replacement only works for replacements approved after this change.
- 2026-10-20: The last button on a new document's row is now Review, which opens the preview dialog (Approve moved into it); removed the Preview document link under the title. The Check text intro now reads "All searchable text within the document is displayed below. This tool is intended to surface any text that should be redacted or removed before posting."
- 2026-10-20: Return on a new document (from its row or its preview) opens a Return dialog with the reason box instead of the Return page. It posts to the same `glover_document_return` handler; from the preview, the preview closes first so dialogs never stack. Without JavaScript, Return still opens the page.
- 2026-10-20: Compare dialog footer is now Edit | Return, Approve Replacement (Return all and Approve Replacement N with several replacements), all buttons; Return shows "Return to submitter" on hover.
- 2026-10-20: Reordered new-document actions to Edit | Return, Approve (all buttons). The preview dialog footer is Open in new tab, Edit | Return, Approve, all buttons.
- 2026-10-20: In the compare dialog, Open file is now Preview and shows the file inside the dialog with Back to comparison, matching the new-document preview. Checked in the browser with a real test PDF.
- 2026-10-20: Check text in the compare dialog now shows in place of the comparison instead of opening a page, and new documents get a Preview document dialog (PDF in a frame, Word and Excel as text) in place of a linked title and file. Split the Check text report into `glover_document_search_render_check_body()` with an approver-only admin-ajax endpoint. Checked in the browser: Check text in and out with Back and Escape (focus returns to the link), the standalone Check text page, and the preview dialog with its footer actions.
- 2026-10-20: Replacement rows now span the full width of the screen. New-document actions sit on one line with Edit as a button and a divider; Return shows "Return to submitter" on hover.
- 2026-10-20: Replacement review redesign, from the approved Design canvas mock-ups ("Pending queue, collapsed", "Queue with compare dialog", "Compare dialog, several replacements"). Pending now lists one row per published document instead of a comparison table per replacement, with Compare opening a dialog. In it the approver can keep the current value of any changed detail, edit the title, approve one of several replacements while returning the others with a reason, or return them all. Added `document-library-review-compare.php` and `assets/review-admin.js`, rewrote the replacement styles in `review-admin.css`, moved Return's steps into `glover_document_library_return_document()`, and made the accent follow the admin color scheme. A throwaway WP-CLI check (grouping, change detection, queue and dialog markup, the page fallback, approving with a kept title while returning the other replacement, cleanup) passed 15 of 16 checks; the one failure was the check's own count, thrown off by real pending replacements in the local database. Browser checks the same day: dialog open and close, focus return, a kept detail restyling its row and the footer sentence, the Return reason box, and the several-replacements cards.

- 2026-10-20: Renamed Review to Documents and made it the only document list in the menu (WordPress's All Documents item is hidden; the classic list is linked from the Approved tab). Review tables now use plain alternating white and light gray rows; only the Changed and New file tags are highlighted, yellow on yellow. Comparison columns are labeled Currently published and Proposed replacement.
- 2026-10-20: Added Document Library > Review, now the menu's first item, with the pending count. **Pending** groups each replacement with the published document it replaces in a side-by-side comparison (title, document date, minutes status, boards, category, topics, file), labels changed details with the word "Changed" plus a highlight, warns when more than one replacement was submitted for the same document, then lists new submissions oldest first. **Approved** lists published documents, newest document date first, with title search, backup status and a "Replaced N times" tag. **Returned** lists returned documents with the reason and date. Approve and Return started from this screen come back to it.
- 2026-10-20: Full-text search. Added the `glover_document_text` table, background indexing of published PDF, Word and Excel files (pdftotext or the bundled smalot/pdfparser 2.13.0), removal of non-town email addresses and excluded words, the Search settings tab, the approver-only "Exclude from text search" field, Check text, Re-index, status list views, and `wp glover documents`. Search now matches titles or text with Best match ranking and page-numbered snippets. Restyled result rows to the approved mock-up: larger forest-green titles, outlined topic pills, an "Effective Date" label, visually hidden column headings, an outlined Download button, and snippet rows. Updated the help copy, the search box label and placeholder, and `library.js` for the Best match default.
- 2026-10-20: Phases 2 and 3: sign-in links now go to `/sign-in/` (Google or emailed code), and approved documents back up to the Shared Drive through `document-library-drive.php`. Added a "failed backups" list view, the backup note and a Retry button in Review details, and a helper so empty meta lists aren't counted as one item.
- 2026-10-19: Moved all library styles into the theme's Sass build: shared colors, typography and core styles in `base/`, reusable pieces (page banner, breadcrumb, buttons, chips, dialog, forms, badges, pagination, document feed) in `components/`, and page-specific styles in `pages/`. Replaced the hand-written banners with `components/common/page-banner.php`, renamed `glover-*` classes to component and page names, and removed `assets/library.css`.
- 2026-10-19: Phase 1. Built the field contract, settings screen, submitter role and profile fields, the ACF front-end submission form with private pending storage, the review queue with Approve and Return, replacement merging with stable addresses, duplicate detection, email notices, the public library with live filtering and facets, single document and board pages, the search help and requirements dialogs, and the accessible-copy request form with its private post type. Added extension points for Google sign-in and Drive backup without building either.
- 2026-10-19: Created this spec from the approved proposal. Phase 0: pointed Document Fields at `document-library`, renamed `document_cateogry` to `document_category`, made both taxonomy fields save real term relationships without in-field term creation, defined the `document-category`, `document-subject`, and hidden `document-board` taxonomies, gave the post type `/documents/` URLs and Document labels, and removed document uploads from Meetings.
