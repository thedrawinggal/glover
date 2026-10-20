# Document Search Proposal (full text)

## Status

Approved by Jenny on 2026-10-20 after the results mock-up, and built the same day. The maintained spec is now the "Full-text search" section of [Document Library](document-library.md); this file is kept as the decision record and is not updated further.

Decided at build time (2026-10-20): bundle smalot/pdfparser as the fallback PDF reader (open question 1), and keep Check text optional (open question 4). Built differently from this proposal: Word files are one block labelled "In the document" with no page numbers, since Word pages depend on how a file is displayed.

Last updated: 2026-10-20.

## Summary and recommendation

Today the library's search box matches document titles only (`glover_document_library_title_search()` in `document-library-query.php`). This proposal makes it also search the text inside approved PDF, DOCX and XLSX files, shows where each match was found ("Page 4: ...the culvert on Daniels Road..."), and keeps title matches at the top.

How much of the contents becomes searchable:

- **PDFs created from Word or another program (born-digital):** essentially all of their text, page by page. This should be every document posted from now on, since the submission guide already asks for real text.
- **DOCX and XLSX:** all of their text. Word files have no fixed pages, so results point to a page only where the file has explicit page breaks; spreadsheets point to the sheet name.
- **Scanned PDFs (images of paper):** not searchable inside. There is no OCR in this proposal (decided 2026-10-20). They are still found by title, and their status says "No text" so the clerk can see which ones they are.

Email addresses (except townofglover.com addresses) and any words or phrases listed in the library settings are removed from the searchable text, so they can't be searched for or appear in result snippets. Phone numbers stay searchable.

**Recommendation:**

1. Extract text in a background job when a document is published or replaced, one row per page, into a **custom table with a MySQL FULLTEXT index**.
2. Use **poppler's `pdftotext`** when the host allows it, and fall back automatically to the pure-PHP **smalot/pdfparser** when it doesn't. Read DOCX and XLSX with PHP's own `ZipArchive` and XML parser.
3. Before the text is stored, **remove email addresses outside townofglover.com and the excluded terms** from the settings screen.
4. Give the approver a **text check** in Review details, so text hidden under black boxes is caught before a document is approved.
5. Rank title matches first, then body relevance, then newest first; show up to two snippets per result with page numbers.
6. Run a throttled backfill only after the legacy migration phase brings the old files into the library.

Every part is free. The one new code dependency is smalot/pdfparser (LGPL-3.0), and only if the host can't run `pdftotext`.

## User need and purpose

Residents rarely know a document's title. They know a road name, a parcel, a person, a dollar amount or a vote ("the Daniels Road culvert", "tax rate 2019"). Today those searches find nothing unless the word happens to be in the title, so residents phone the clerk. Searching inside documents turns the library into an answer to "where did the town decide this?", and it is especially valuable for the ~1,300 legacy agendas and minutes that will move to an archive area.

Making document text easy to search also makes personal details and badly redacted text easy to find. The scrubbing, the excluded-terms setting and the approver's text check exist to keep that in check.

## Proposed scope

- Text extraction for published documents' current files: PDF (per page), DOCX (per explicit page break, otherwise one block), XLSX (per sheet).
- Removal of email addresses (other than townofglover.com) and listed excluded terms from the searchable text.
- A custom index table and an index status on each document, shown in its Review details box.
- A text check for the approver in Review details, before approval.
- Library search that matches titles or document text, with title matches ranked first.
- Result snippets with the page or sheet, linking to that page of the PDF where the browser supports it.
- Re-indexing on replacement and when the excluded-terms setting changes; removal from the index on unpublish, trash or delete.
- Admin tools: a status count on the Document Library settings screen, a "Re-index" button per document, and a WP-CLI command for the backfill.
- Updated search help copy in the existing help dialog.

## Non-goals

- OCR of scanned PDFs, through Google Drive or anything else (decided 2026-10-20). Could be revisited later as its own proposal.
- Paid search services (Algolia, ElasticPress hosting, Adobe).
- Elasticsearch, Solr, Meilisearch or any separate search server. Hosting is undecided and the scale (about 10,000 pages) doesn't need one.
- Indexing pending, returned, draft, private or previous-version files, ever.
- Changing, redacting or re-saving the files themselves. Scrubbing only affects the search index; the downloadable file is untouched.
- Automatically detecting black boxes drawn over text. It isn't reliable without rendering every page, so the approver's text check does this job instead.
- Removing names. Names are what residents most often search for, and there is no reliable way to tell a private person's name from a road or business name. Specific names can go in the excluded terms.
- Site-wide search (pages, events, meetings). This covers the library's own search box and REST endpoint only.
- Spelling correction, synonyms ("rd" for "road"), or stemming beyond what MySQL does.

## Source evidence

- `includes/features/document-library/document-library-query.php`: title-only search through `posts_search`, three `WP_Query` calls per request (results plus category and topic facets), and the REST endpoint `glover/v1/documents` that returns server-rendered results markup.
- `document-library-integrations.php`: the lifecycle actions `document_published ($post_id)`, `document_unpublished ($post_id)` and `document_replaced ($post_id, $new_attachment_id, $previous_attachment_id)`.
- `document-library-drive.php`: the WP-Cron job and retry pattern this feature would copy.
- `document-library-review.php`: the Review details box where the text check and index status would appear.
- `acf-json/group_glover_document_library_settings.json`: the settings screen that would gain the search fields.
- [Accessibility requirements](../requirements/accessibility.md): the legacy backlog counts (1,446 files, 1,429 of them PDFs, about 1,230 agendas and minutes) and the note that the number of scanned PDFs is unknown.
- Local environment check on 2026-10-20: PHP 8.5 with `zip`, `xml` and `xmlreader`; MySQL 8.0.44; no `pdftotext` installed. Only one PDF is in local uploads, so no measurement has been possible yet.

## Options compared

### PDF text extraction

| | poppler `pdftotext` | smalot/pdfparser (pure PHP) |
| --- | --- | --- |
| Cost | Free (GPL command-line tool) | Free (LGPL-3.0 library) |
| Needs from the host | `poppler-utils` installed and PHP allowed to run programs (`proc_open` or `exec`) | Nothing beyond PHP |
| Typical hosts | Any VPS; some shared hosts; usually not managed WordPress hosts | Every host |
| Accuracy | Very good, including columns and unusual fonts | Good on simple office exports; weaker on some font encodings, ligatures and multi-column layouts |
| Pages | Form-feed between pages, so page numbers are exact | `getPages()` gives exact pages |
| Speed and memory | Fast, separate process, little PHP memory | Slower; large or image-heavy PDFs can use hundreds of MB of PHP memory |
| Fits the "no libraries" rule from Google Workspace | Yes | No: needs Composer or a vendored copy in the theme |

**Recommendation:** an extractor interface with both. Detect `pdftotext` at run time and prefer it; fall back to a vendored, version-pinned smalot/pdfparser with a file-size and page-count cap. Record which method was used on each document, so a host move can trigger a re-index of the fallback ones. If the chosen host has `pdftotext`, smalot can be dropped before launch.

Considered and rejected: Apache Tika (needs Java, a server), Python tools (a second runtime on the host), and online converters (cost, and they send town files to a third party).

### Storage

| | Custom table with FULLTEXT index (recommended) | Post meta |
| --- | --- | --- |
| Page numbers | One row per page, natural | One blob per document, or a serialized array that SQL can't search per page |
| Search speed | Indexed; milliseconds at ~10,000 rows | `LIKE '%word%'` scans every document's text on every keystroke |
| Relevance | `MATCH ... AGAINST` gives a score | None; only "contains" |
| Side effects | None on other queries | WordPress loads all post meta for every document in a result list, so each library page would pull megabytes of text into memory |
| Cost | A table created on first use, with a schema version option | No schema work |

**Recommendation:** the custom table. Post meta is fine for the small index status fields only.

## Redactions and personal information

### The redaction problem

A common way of "redacting" a PDF is to draw a black box or black highlight over text. The text is still in the file underneath. Anyone can copy it out of the downloaded PDF today, and full-text search would make it much easier to find, because it would appear in search snippets on the public site.

Nothing on the website can fix a badly redacted file after the fact: as long as the file is published, the hidden text can be copied out of the download. So the safeguards below aim to **catch it before approval**, and to keep the search index from amplifying it.

### What we can do, in order of importance

1. **Approver text check (recommended, catches it before publishing).** Review details on a pending document gets a "Check text" button. It extracts the pending file's text on the spot (privately: it is shown to the approver and never stored in the search table) and shows it page by page, with removed email addresses and excluded terms highlighted. If the approver sees text that should have been blacked out, they Return the document with a reason. The same check flags PDFs that contain redaction marks that were never applied (Acrobat's "mark for redaction" leaves the text in place until "Apply" is clicked), which is a cheap, reliable check.
2. **Guidance in the requirements guide.** A short "How to redact" section: delete the text in Word before saving as PDF, or use a real redaction tool that removes text. Never use black highlighting or drawn boxes. The clerk keeps the guide current; this needs a few lines of copy, not code.
3. **Automatic scrubbing of the search index** (see below). Removes non-town email addresses and listed terms from what search can find and show.
4. **"Exclude from text search" on a document.** An approver-only switch for documents whose contents shouldn't be searchable at all. The document is still found by title.

If a badly redacted document is found after publishing, the fix is the normal one: unpublish it, or replace it with a properly redacted file. Both already remove the old text from the index.

### Automatic scrubbing

Before page text is stored, the indexer replaces matches with nothing searchable (a space), so they can't be found and never appear in snippets:

- **Email addresses:** anything shaped like `name@domain.tld`, except addresses at `townofglover.com` (the town's own staff and group addresses such as clerk@ and webmaster@), which stay searchable. The kept domain is read from `GLOVER_GOOGLE_DOMAIN` when it is set, so it stays in step with the Google integration.
- **Excluded terms** from the settings screen (below).

Phone numbers are not removed (decided 2026-10-20). A specific number can still be added to the excluded terms.
Scrubbing is applied to document text only. Titles are left alone, because the approver writes and checks every title.

### Settings for search

Document Library > Settings gets a "Search" tab (fields added to `acf-json/group_glover_document_library_settings.json`, admins only):

| Setting | Meta name (options) | Notes |
| --- | --- | --- |
| Remove email addresses | `document_library_search_remove_emails` | True/false, on by default. townofglover.com addresses are always kept |
| Excluded words and phrases | `document_library_search_excluded_terms` | Textarea. Separate entries with commas or new lines, for example `jenny@example.com, 123 Main Street, John Q. Smith` |

How excluded terms behave:

- Matching ignores capitals and extra spaces, and matches whole words or phrases only, so excluding "Smith" doesn't remove "Smithfield".
- Saving the settings with a changed list re-indexes every published document in the background, throttled like the backfill. The status count on the settings screen shows progress. Until it finishes, older text may still match.
- A search for an excluded term finds only documents with that term in their title.
- The list is stored in the database, visible only to administrators, and never shown on the public site.
- Entries shorter than three characters are ignored, with a note on the settings screen, because MySQL doesn't index them anyway.

## How it would work

1. A document is published (or replaced). The existing lifecycle action fires.
2. A new `document-library-search.php` listener records status Queued and schedules a WP-Cron job, following the Drive backup's pattern (single event, retries at increasing delays, failure shown in Review details with a Re-index button).
3. The job checks, at run time, that the post is still published, isn't excluded, and that the attachment is still its current file and not in the private folder. If not, it stops and removes any rows.
4. It extracts text by type, one record per page or sheet, normalizes whitespace, applies the scrubbing, and replaces that document's rows in one transaction.
5. If a PDF yields almost no text (fewer than about 25 characters per page on average), the status becomes No text. That is almost always a scan.
6. Search reads the table through the existing query functions; the REST endpoint and live filtering need no new route.

### Data contracts

Table `{$wpdb->prefix}glover_document_text`, InnoDB, utf8mb4:

| Column | Type | Notes |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED, primary key | |
| `post_id` | BIGINT UNSIGNED, indexed | The `document-library` post |
| `attachment_id` | BIGINT UNSIGNED | The file the text came from, so stale jobs can be detected |
| `part` | SMALLINT UNSIGNED | 0 = whole document (used for matching), 1 and up = page or sheet order |
| `part_label` | VARCHAR(190) | "Page 4", or the sheet name, or "" |
| `source` | VARCHAR(20) | `pdftotext`, `pdfparser`, `docx`, `xlsx` |
| `content` | MEDIUMTEXT, FULLTEXT index | Plain text, whitespace normalized, scrubbed |

The part 0 row holds the whole document's text so a search for "culvert Daniels" matches a document where the two words are on different pages. Page rows exist for snippets and page numbers. At an estimated 10,000 pages of about 3 KB each, the table would be around 60 MB including the index, which any MySQL host handles easily.

New system meta on the document (code-written, never edited by hand):

| Meta key | Purpose |
| --- | --- |
| `_glover_text_index_status` | `queued`, `indexed`, `no_text`, `failed`, `excluded` |
| `_glover_text_index_note` | Error detail, method used, page count |
| `_glover_text_index_hash` | SHA-1 of the indexed file plus the scrubbing settings, so re-runs skip unchanged files and a settings change forces a re-index |

New field on the document: `document_exclude_from_text_search` (true/false, editable by the approver, backup approver and administrators only).

### Matching and ranking

- The search words are cleaned and turned into a MySQL Boolean-mode query in PHP: each word becomes `+word*` (all words required, prefix match so "culvert" finds "culverts"), and quoted text becomes a phrase. MySQL's operator characters typed by a resident are stripped, so input can never cause an SQL error.
- A document matches when **all words are in its title** (today's behavior, kept as a `LIKE` match) **or its part 0 text matches**.
- When there is a search, a new default sort, **Best match**, orders by:
  1. all words in the title,
  2. some words in the title,
  3. body relevance (`MATCH ... AGAINST` score on part 0),
  4. newest `document_date`.

  Residents can still choose Most recent, Oldest or Title, which behave as today. Without a search, the default stays Most recent.
- Implementation: `glover_document_library_title_search()` becomes a broader search filter, and `posts_join`/`posts_orderby` add a derived table of matching post IDs and scores. Because `glover_document_library_facet_counts()` reuses `glover_document_library_query_args()`, the category and topic counts automatically include text matches. The matching IDs and scores are computed once per request and reused by the three queries.

### Snippets with page numbers

- For the up to 20 results on the current page, one extra query fetches each document's best one or two matching page rows (by score, then page order).
- PHP cuts about 30 words around the first matched word, escapes it, and wraps matched words in `<mark>`. Because the stored text is already scrubbed, snippets can't show removed details.
- On the full table (1,200px and wider), each result becomes its own `<tbody>`: the usual row, then a second row holding the snippets. That row spans the Title, Topic, Department and Date columns and stops short of the Download button, with no line between it and the result's main row. Striping follows results rather than rows. On stacked cards (narrower screens) the snippets sit inside the Title cell under the title, below a subtle divider (`--color-line-subtle`). The markup carries both copies, and CSS shows only one at a time with `display: none`, so screen readers read the snippets once. Example: "Page 4: …approved the replacement of the culvert on Daniels Road at a cost of…".
- For PDFs the page label is a link to the file with `#page=4` (decided 2026-10-20). It opens in the same tab, like the Download link, so Back returns to the results. Desktop Chrome, Edge, Firefox and Safari open the PDF at that page. Phones often open it at page 1 or download it, which is harmless. The searched word isn't highlighted inside the PDF.
- Spreadsheet sheet names and "In the document" labels are plain text, since there is no page to jump to.
- XLSX snippets name the sheet ("Sheet “General Fund”:"). DOCX without page breaks says "In the document:".
- Title-only matches show the highlighted title and no snippet, with no explanatory note.
- Row styling follows Jenny's screenshot of 2026-10-20 (see the mock-up): 1.25rem forest-green serif titles without underline until hover, an outlined topic pill, board and date in the muted color with a small bold "Effective Date" label above the date, an outlined Download button in link green, and snippets at 1.0625rem in ink with the page label bold and underlined. On the full table the column headings are visually hidden but kept for screen readers, and the "Date" heading becomes "Effective date". The highlight color is `--color-highlight` (#efd673).

### Re-indexing and removal

| Event | Action |
| --- | --- |
| `document_published` | Queue indexing |
| `document_replaced` | Delete rows for the old attachment, queue the new file. Previous versions are private and never indexed |
| `document_unpublished` | Delete all rows |
| Trash or permanent delete of a document | Delete all rows (`wp_trash_post`, `before_delete_post`) |
| "Exclude from text search" turned on | Delete all rows, status `excluded` |
| Scrubbing settings or excluded terms changed | Re-index all published documents, throttled |
| Extractor change (host move) | WP-CLI or settings-screen "Re-index all", throttled |

Defense in depth, so pending or private files can never leak: indexing happens only from these hooks; the job re-checks status and private storage when it runs; every search query also joins `wp_posts` with `post_status = 'publish'` (which `WP_Query` already does); and nothing reads the table except the published-only library query. The approver's text check never writes to the table.

### Plugging into live filtering and the REST endpoint

No new endpoint and no change to `assets/library.js` beyond small copy. `glover/v1/documents` already returns the server-rendered `results` component, so snippets arrive with it. The `sort` filter gains a `relevance` value, which `glover_document_library_parse_filters()` and the sort labels must accept; `glover_document_library_url()` leaves it out of URLs when it's the default for a search. The existing typing pause before a live update stays, which keeps query load low.

## Accessibility considerations

The library's results must meet WCAG 2.1 AA (see [the accessibility requirements](../requirements/accessibility.md)).

- Snippets are plain text. On the full table they are in a row of their own directly after the result's row, inside the same `<tbody>`; on stacked cards they are inside the Title cell. The hidden copy uses `display: none`, so it is never read.
- `<mark>` is not announced by most screen readers, so highlighting only adds emphasis. The "Page 4:" lead-in carries the meaning in text. Highlight colors come from `base/_colors.scss` and must keep text at 4.5:1 contrast. (SC 1.4.1, 1.4.3)
- Page links have unique accessible names, for example "Page 4 of Select Board minutes, 2026-03-04 (PDF)", using visually hidden text. (SC 2.4.4)
- The live region keeps announcing only the results count, so a screen reader isn't flooded with snippets; snippets are read when the user reaches each result. Focus behavior is unchanged. (SC 4.1.3)
- Scanned PDFs stay unsearchable inside and inaccessible. A scanned PDF found by title still needs an accessible copy on request, and the "Request an accessible copy" link should be easy to reach from each result.
- Help dialog copy is updated to explain that search looks inside documents (except scanned ones), how to use quotes for phrases, and that very short words (under three letters), common words, and email addresses outside the town aren't searched.
- The approver's text check is part of the staff editor, which is outside the conformance scope, but it should still use a real heading per page and text labels for flagged items, not color alone.
- Verification adds a keyboard and screen-reader check of results with snippets, and a 375px check.

## Backfill of legacy files

Bulk migration of the ~1,446 legacy files is a separate, later phase with its own sign-off. The backfill runs after it, or alongside it as each batch is imported.

1. **Text pass:** `wp glover documents index --missing --batch=50` (also available as a throttled WP-Cron loop for hosts without WP-CLI). Each document is skipped if its hash is unchanged. With `pdftotext`, expect well under an hour for the whole set; with smalot, a few hours spread over cron runs.
2. **Report:** the settings screen shows counts by status (indexed, no text, failed, excluded) and links to a filtered document list, so the clerk can see which old files are scans and remain searchable by title only.
3. **Order:** current documents first (the ~120 that stay current), then newest to oldest.
4. **Redaction risk in old files:** legacy files were never through the text check. Before the backfill, the excluded-terms list should be filled in, and the spike sample should be read for hidden text to judge how big the problem is.

## Phasing

Rough effort, to be firmed up after the spike.

- **S0, spike (about 1 day):** pick 50 representative legacy PDFs from the export (agendas, minutes, reports, across 2007 to 2026). Measure how many are scans, compare `pdftotext` and smalot on the born-digital ones, test the email pattern against real text, and look for hidden text under black boxes. Report the numbers before S1 starts.
- **S1, search (about 4 to 5 days):** table, extractors, scrubbing, the settings fields, background job and status, matching, ranking, snippets, the exclusion field, help copy, smoke tests, and doc updates.
- **S2, approver text check (about 1 to 2 days):** the "Check text" button and page-by-page view in Review details, highlighting, and the unapplied-redaction warning.
- **S3, backfill tooling (about 1 to 2 days):** WP-CLI command, throttled cron loop, status report. Run with the migration phase.

## Smoke-test checklist (for the build)

1. Publish a born-digital PDF. Confirm status Indexed, one row per page plus part 0, and that a word from page 3 finds it with a "page 3" snippet.
2. Search for a word that is in one document's title and another's body. Confirm the title match is first.
3. Submit a document and leave it Pending. Confirm none of its words can be found, and no rows exist.
4. Replace the document. Confirm old-file words no longer match and new-file words do.
5. Unpublish and trash a document. Confirm its rows are gone.
6. Publish a PDF containing a personal email address, a townofglover.com address and a phone number. Confirm the personal address can't be found or seen in snippets, and the town address, the phone number and the surrounding words can.
7. Add a phrase to Excluded words and phrases and save. Confirm the re-index runs, and the phrase can't be found or seen in snippets, but a document with it in the title is still found by title.
8. Publish a scanned PDF. Confirm status No text and that it is found by title.
9. Publish a DOCX and an XLSX. Confirm the snippet labels.
10. Mark a document as excluded. Confirm title search still finds it and its text doesn't.
11. On a pending PDF with text under a drawn black box, use Check text. Confirm the hidden text is shown and non-town email addresses are flagged.
12. Type MySQL operators (`+-<>()~*"@`) into the search box. Confirm no error.
13. Confirm category and topic counts reflect text matches.
14. With JavaScript off, confirm the same results through Apply filters.
15. Keyboard and screen-reader pass of results with snippets; 375px check.

## Known limitations and risks

- **Scrubbing protects search, not files.** Removed details are still in the downloadable file. Only the approver's text check and proper redaction before upload protect the file itself.
- **Redactions in legacy files.** The ~1,446 legacy files were never checked. Some may hide text under black boxes, and that text is already downloadable on the current site.
- **Personal information becomes easier to find.** Names, addresses and other details in minutes and reports were technically public but hard to find. Scrubbing covers non-town email addresses and listed terms, not phone numbers, names or street addresses in general. The town should be comfortable with that before launch.
- **Pattern limits.** The email pattern will miss spelled-out addresses ("name at domain dot com"). The spike tests it against real text.
- **Excluded terms take effect after a re-index.** A large re-index on a slow host may take an hour or more.
- **Scanned PDFs aren't searchable inside.** Without OCR, a share of the legacy archive (unknown until the spike) stays title-only.
- **Hosting.** Without shell access there is no `pdftotext`, and extraction uses smalot (slower, somewhat less accurate). WP-Cron needs a real server cron on production, as for Drive backup.
- **MySQL FULLTEXT rules.** InnoDB ignores words under 3 characters by default (`innodb_ft_min_token_size`, not changeable on shared hosting), so "R1" or "VT" won't be found in body text. A built-in stopword list drops words such as "the", "will" and "about". Stopwords can probably be turned off for this table at index-creation time with a session setting; confirm in S1. MariaDB behaves slightly differently and should be tested if the host uses it.
- **Page links.** `#page=` is a browser convention, not a standard; some viewers and all downloads ignore it.
- **Memory and time.** Very large PDFs (planning maps, long reports) can exceed PHP limits with smalot. Files over a set size or page count are marked `failed` with a note instead of retried forever.
- **Index drift.** If a file changes outside the library's hooks (manual upload replacement in wp-admin), the index goes stale until the hash check in a re-index pass catches it.

## Open questions

1. Is Jenny comfortable with the vendored smalot/pdfparser fallback, given the Google integration's "no libraries" rule, or should the build wait until hosting is chosen and require `pdftotext`?
2. Should the host decision weigh poppler support?
3. Should archived (pre-2028) documents be searchable by default? This proposal assumes yes.
4. Should the approver's text check be required (Approve disabled until it has been opened), or optional?
5. Is the town (and Olivia) comfortable with names and street addresses in old minutes becoming easy to find? Does Vermont's public records practice suggest other categories to remove, such as Social Security or account numbers?
6. Should "Best match" be the default sort whenever there is a search, or should Most recent stay the default with Best match as an option?

## Decisions needed for sign-off

- Approve the recommended architecture (custom FULLTEXT table, `pdftotext` with smalot fallback, no OCR).
- Approve the scrubbing rules and the Search settings (remove non-town email addresses, excluded words and phrases).
- Approve the approver text check and the per-document exclusion field.
- Approve S0 (the spike) as the next step, with S1 to S3 confirmed after its numbers come back.

## Material change history

- 2026-10-20: Phone numbers stay searchable; email removal now keeps townofglover.com addresses.
- 2026-10-20: Restyled result rows to match Jenny's screenshot; removed the divider above snippets on the full table (kept on stacked cards).
- 2026-10-20: Snippets moved to their own row under each result on the full table, with a subtle divider; they stay under the title on stacked cards.
- 2026-10-20: After the results mock-up (`project-files/mockups/document-search-results.html`): page labels stay links that open the PDF at that page; title-only matches carry no explanatory note.
- 2026-10-20: Revised after Jenny's review: removed OCR, added removal of email addresses and phone numbers from the index, an "Excluded words and phrases" setting, a redaction section, and an approver text check (new phase S2).
- 2026-10-20: Drafted this proposal for full-text search across Document Library files. Not yet approved.
