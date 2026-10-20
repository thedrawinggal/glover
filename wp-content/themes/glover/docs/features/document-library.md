# Document Library

## Status

Phase 1 (the core library) is built and verified locally. It includes the field contract, settings screen, submitter permissions, submission form with private pending storage, review queue, public library with live filtering, single document pages, board feeds, and the accessible-copy request form. Sign-in uses the standard WordPress login for now.

Built as architecture only, waiting for Google Workspace admin access:

- Phase 2: Google and emailed-code sign-in.
- Phase 3: Drive backup.
- Site email delivery through Workspace. Notices are sent with `wp_mail()`, so they only arrive once mail is configured.

The approved proposal, with its decision log and diagrams, is the Claude Doc "Glover Document Library Proposal" (https://claude.ai/code/artifact/f145edb5-b20d-4e67-b148-91b6b7a4ef7d). Jenny approved it in chat on 2026-10-19. This file is the maintained spec from here on.

Last updated: 2026-10-19.

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
- No new paid licenses or add-ons. Anything a paid add-on would do is written in-house.

## Current non-goals

- Anonymous or public uploads.
- Drive as the public file host, or any sync from Drive back to the site.
- Searching inside PDF text.
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

### Submitters

A submitter is a WordPress user, so Phase 2 sign-in only has to sign people in as users. The Document Submitter role has `read` and `submit_glover_documents`; administrators and editors are also granted the capability. On each user's profile (`acf-json/group_glover_document_submitter.json`, visible to administrators only), the settings are:

- `document_submitter_boards`: the boards they may post for. Empty means every board for approvers, administrators and editors.
- `document_submitter_auto_approve`: publish without review.

Only users who can `promote_users` can change these, even on their own profile.

### Accessible-copy requests

`access-copy-request` (`acf-json/post_type_glover_access_copy_request.json`), private, listed under Document Library. Each request is saved before it is emailed, so a mail failure never loses it. The form has a honeypot, a five-per-hour limit per IP address, and an optional Turnstile check.

## Extension points for Google (not built)

- **Sign-in:** filter `glover_document_library/sign_in_url`.
- **Backup:** register on `glover_document_library/backup_handlers`, handle `glover_document_library/backup_requested ($post_id, $attachment_id)`, and record progress with `glover_document_library_set_backup_status()`. With no handler registered, status is "Not configured".
- **Lifecycle actions:** `glover_document_library/document_published`, `document_unpublished`, and `document_replaced ($post_id, $new_attachment_id, $previous_attachment_id)`.

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
- `document-library-notifications.php`: emails.
- `document-library-lifecycle.php`: publishing guard, publish and unpublish handling, replacement merge, duplicate detection.
- `document-library-submission.php`: submission form, title and file field adjustments, replaces choices, validation, completing a submission.
- `document-library-review.php`: list columns, Approve and Return, Published without review view, pending count, Review details box.
- `document-library-query.php`: filter parsing, query building, title-only search, facet counts, library URLs, REST endpoint `glover/v1/documents`.
- `document-library-public.php`: asset loading, download links, dates, board feed query, help contact.
- `document-library-requests.php`: accessible-copy request handler.
- `assets/library.js` (live filtering, year slider, topics and filters toggles), `assets/dialogs.js` (help and requirements dialogs), `assets/submission.js` (minutes status toggle). These load directly from the feature folder; styles do not (see Styles).
- `archive-document-library.php`, `single-document-library.php`, `single-department-board.php`, `template-document-submission.php`, `template-accessible-copy-request.php`.
- `components/document-library/`: `filters`, `summary`, `active-filters`, `results`, `help`, `requirements`, `latest-documents`.
- `components/common/page-banner.php`: the site-wide page banner (breadcrumb, title, optional action and note) used by every library template.

## Styles

All styles are Sass in `src/styles/`, compiled by Parcel into `dist/styles/app.css`, which `functions.php` loads on every page. The library has no stylesheet of its own.

- `base/_colors.scss`: the color palette as Sass variables and `--color-*` custom properties. Rules use the custom properties.
- `base/_typography.scss`: typefaces, h1 to h6 sizes, body text, the `.rich-text` wrapper for editor content, and the `eyebrow` mixin.
- `base/_core.scss`: page background, links, focus ring, `.container`, `.content-narrow`, `.visually-hidden`, `.skip-link`, reduced motion.
- `components/`: `_page-banner`, `_breadcrumb`, `_button`, `_chip`, `_dialog`, `_form`, `_badge`, `_pagination`, `_document-feed`. Shared across pages.
- `pages/`: one file per page for styles unique to it: `_documents` (the library), `_document` (single document), `_submit-document`, `_department-board`. The request page needs nothing beyond components.

Build the CSS alone with `npx parcel build src/styles/app.scss --dist-dir dist/styles` from the theme folder. A full `npm install` still needs the licensed GSAP tarball (see CLAUDE.md).

## Dependencies

- WordPress and ACF Pro (already licensed), with Local JSON loading from the theme.
- The theme's Parcel and Sass build for styles. The library's scripts load directly from the feature folder.
- Planned, no new cost: Google Workspace (sign-in, Shared Drive, email), a town-owned Google Cloud project, and Cloudflare Turnstile.

## Accessibility considerations

The public library, board feeds, document pages, signed-out forms, both dialogs, and every document posted from now on must meet WCAG 2.1 AA by 2028-04-26. See [the accessibility requirements](../requirements/accessibility.md).

- Filters are a plain GET form and work without JavaScript. Live updates announce the results count in a polite live region, keep focus on the changed control, and wait for a pause in typing. After a chip or pagination link, focus moves to the results count.
- Year range: two labeled dropdowns, plus a slider built from two native range inputs labeled "From year" and "To year" that can't pass each other.
- Topic chips are real checkboxes. Active filters are links, so they work without JavaScript.
- Results are a real table with explicit roles kept for the stacked phone layout. Each Download link includes the document title, format and size for screen readers.
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

## Verification approach

- Parse every ACF JSON file, check field keys for duplicates, and verify every conditional-logic reference resolves.
- `php -l` every feature, template and component file; `node --check` every feature script.
- End-to-end WP-CLI script covering private storage and HTTP blocking, approval, slug assignment, replacement merge, duplicate detection, the publishing guard, submitter board limits, search and facets, and the board feed. 22 of 22 checks passed on 2026-10-19 with all test content removed afterward.
- HTTP checks of every template and the REST endpoint, and an HTTP submission of the request form.
- Browser checks of live filtering, focus, dialogs and the phone layout.

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

## Open questions

- Where policies, notices and flyers, and budgets belong, since the current categories have no term for them.
- The starting topic list (Recreation and Roadwork exist so far).
- The backup approver at launch, and who besides Jenny and Olivia gets Auto-approve.
- Final search help copy (Jenny is writing it).
- Google Workspace admin access (Jenny is requesting it), whether the plan allows SMTP relay and includes Shared Drives, and who controls DNS for townofglover.com.
- How many submitters are outside townofglover.com.

## Material change history

- 2026-10-19: Moved all library styles into the theme's Sass build: shared colors, typography and core styles in `base/`, reusable pieces (page banner, breadcrumb, buttons, chips, dialog, forms, badges, pagination, document feed) in `components/`, and page-specific styles in `pages/`. Replaced the hand-written banners with `components/common/page-banner.php`, renamed `glover-*` classes to component and page names, and removed `assets/library.css`.
- 2026-10-19: Phase 1. Built the field contract, settings screen, submitter role and profile fields, the ACF front-end submission form with private pending storage, the review queue with Approve and Return, replacement merging with stable addresses, duplicate detection, email notices, the public library with live filtering and facets, single document and board pages, the search help and requirements dialogs, and the accessible-copy request form with its private post type. Added extension points for Google sign-in and Drive backup without building either.
- 2026-10-19: Created this spec from the approved proposal. Phase 0: pointed Document Fields at `document-library`, renamed `document_cateogry` to `document_category`, made both taxonomy fields save real term relationships without in-field term creation, defined the `document-category`, `document-subject`, and hidden `document-board` taxonomies, gave the post type `/documents/` URLs and Document labels, and removed document uploads from Meetings.
