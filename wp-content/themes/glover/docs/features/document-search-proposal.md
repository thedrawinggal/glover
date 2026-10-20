# Document Search Proposal (full text)

## Status

Proposal, awaiting Jenny's sign-off. Nothing here is built. If approved, this becomes a section of [Document Library](document-library.md) (or its own maintained spec) and "Searching inside PDF text" comes off that document's non-goals list.

Last updated: 2026-10-20.

## Summary and recommendation

Today the library's search box matches document titles only (`glover_document_library_title_search()` in `document-library-query.php`). This proposal makes it also search the text inside approved PDF, DOCX and XLSX files, shows where each match was found ("Page 4: ...the culvert on Shadow Lake Road..."), and keeps title matches at the top.

How much of the contents becomes searchable:

- **PDFs created from Word or another program (born-digital):** essentially all of their text, page by page. This should be every document posted from now on, since the submission guide already asks for real text.
- **DOCX and XLSX:** all of their text. Word files have no fixed pages, so results point to a page only where the file has explicit page breaks; spreadsheets point to the sheet name.
- **Scanned PDFs (images of paper):** searchable only through OCR. With Google Drive OCR, quality is usually good on clean typed pages and poor on handwriting, faint copies and tables. How many of the ~1,430 legacy PDFs are scans is still unknown, so the share of the legacy archive that ends up well searchable is unknown until the spike in Phase S0 measures it.

**Recommendation:**

1. Extract text in a background job when a document is published or replaced, one row per page, into a **custom table with a MySQL FULLTEXT index**.
2. Use **poppler's `pdftotext`** when the host allows it, and fall back automatically to the pure-PHP **smalot/pdfparser** when it doesn't. Read DOCX and XLSX with PHP's own `ZipArchive` and XML parser.
3. Send PDFs with little or no text to **Google Drive OCR** through the existing service account and Shared Drive, at no cost.
4. Rank title matches first, then body relevance, then newest first; show up to two snippets per result with page numbers.
5. Run a throttled backfill only after the legacy migration phase brings the old files into the library.

Every part is free and uses things the town already has. The one new code dependency is smalot/pdfparser (LGPL-3.0), and only if the host can't run `pdftotext`.

## User need and purpose

Residents rarely know a document's title. They know a road name, a parcel, a person, a dollar amount or a vote ("the Daniels Road culvert", "tax rate 2019"). Today those searches find nothing unless the word happens to be in the title, so residents phone the clerk. Searching inside documents turns the library into an answer to "where did the town decide this?", and it is especially valuable for the ~1,300 legacy agendas and minutes that will move to an archive area.

## Proposed scope

- Text extraction for published documents' current files: PDF (per page), DOCX (per explicit page break, otherwise one block), XLSX (per sheet).
- OCR through Google Drive for PDFs, or pages, that have no usable text layer.
- A custom index table and an index status on each document, shown in its Review details box.
- Library search that matches titles or document text, with title matches ranked first.
- Result snippets with the page or sheet, linking to that page of the PDF where the browser supports it.
- Re-indexing on replacement, removal from the index on unpublish, trash or delete.
- Admin tools: a status count on the Document Library settings screen, a "Re-index" button per document, and a WP-CLI command for the backfill.
- Updated search help copy in the existing help dialog.

## Non-goals

- Paid search or OCR services (Algolia, ElasticPress hosting, Adobe, Google Document AI, Cloud Vision). Drive OCR is used only because it is free with Workspace.
- Elasticsearch, Solr, Meilisearch or any separate search server. Hosting is undecided and the scale (about 10,000 pages) doesn't need one.
- Indexing pending, returned, draft, private or previous-version files, ever.
- Site-wide search (pages, events, meetings). This proposal covers the library's own search box and REST endpoint only.
- Treating OCR text as an accessible version of a scanned document. It is not one (see Accessibility).
- Fixing or re-saving the source files. Extraction only reads them.
- Spelling correction, synonyms ("rd" for "road"), or stemming beyond what MySQL does.
- Searching images, scanned handwriting, or text inside images embedded in DOCX files.

## Source evidence

- `includes/features/document-library/document-library-query.php`: title-only search through `posts_search`, three `WP_Query` calls per request (results plus category and topic facets), and the REST endpoint `glover/v1/documents` that returns server-rendered results markup.
- `document-library-integrations.php`: the lifecycle actions `document_published ($post_id)`, `document_unpublished ($post_id)` and `document_replaced ($post_id, $new_attachment_id, $previous_attachment_id)`.
- `document-library-drive.php` and `wp-content/mu-plugins/glover-google/client.php`: the WP-Cron job and retry pattern, and `glover_google_access_token()` / `glover_google_request()`. The service account already holds the full `drive` scope and is Content manager on the Shared Drive, so OCR needs no new Google setup.
- [Accessibility requirements](../requirements/accessibility.md): the legacy backlog counts (1,446 files, 1,429 of them PDFs, about 1,230 agendas and minutes) and the note that the number of scanned PDFs is unknown.
- Local environment check on 2026-10-20: PHP 8.5 with `zip`, `xml` and `xmlreader`; MySQL 8.0.44; no `pdftotext`, `qpdf`, Ghostscript or Tesseract installed. Only one PDF is in local uploads, so no measurement has been possible yet.

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
| Also offers | `pdfseparate` (split pages for OCR), `pdfinfo` (page count) | Nothing comparable |

**Recommendation:** an extractor interface with both. Detect `pdftotext` at run time and prefer it; fall back to a vendored, version-pinned smalot/pdfparser with a file-size and page-count cap. Record which method was used on each document, so a host move can trigger a re-index of the fallback ones. If the chosen host has `pdftotext`, smalot can be dropped before launch.

Considered and rejected: Apache Tika (needs Java, a server), Python tools (a second runtime on the host), and online converters (cost, and they send town files to a third party).

### OCR for scanned PDFs

| | Google Drive OCR (recommended) | Tesseract on the server | None |
| --- | --- | --- | --- |
| Cost | Free with Workspace; Drive API has no charge | Free | Free |
| Host needs | Outbound HTTPS only | Tesseract plus Ghostscript or poppler to render pages; CPU heavy | None |
| Quality | Good on typed pages | Comparable on clean typed pages, more tuning needed | Scans unsearchable |
| Privacy | Files already go to the town's own Shared Drive for backup | Stays on the server | n/a |

How Drive OCR would work: upload the PDF (or one page of it) to a `_search-ocr` work folder in the Shared Drive with a Google Docs target type and `ocrLanguage=en`, export the resulting Doc as plain text, then move the temporary Doc to the drive's trash. Only published files are ever sent, and they are files the Drive backup already copies to the same drive.

Two Drive behaviors must be confirmed in the spike before relying on them:

- Google's help pages describe OCR limits for PDFs (a recommended size around 2 MB, and text conversion limited to the first pages of a long PDF). If those limits hold through the API, long scans must be sent one page at a time.
- Whether the converted Doc keeps page breaks. If it doesn't, page numbers come only from sending pages one at a time.

Sending single pages needs a page splitter, which means `pdfseparate` or `qpdf` on the host. Without one, OCR falls back to the whole file, and its snippets say "Scanned document" instead of a page number. This is the strongest argument for a host with poppler.

### Storage

| | Custom table with FULLTEXT index (recommended) | Post meta |
| --- | --- | --- |
| Page numbers | One row per page, natural | One blob per document, or a serialized array that SQL can't search per page |
| Search speed | Indexed; milliseconds at ~10,000 rows | `LIKE '%word%'` scans every document's text on every keystroke |
| Relevance | `MATCH ... AGAINST` gives a score | None; only "contains" |
| Side effects | None on other queries | WordPress loads all post meta for every document in a result list, so each library page would pull megabytes of text into memory |
| Cost | A table created on theme activation or first use, with a schema version option | No schema work |

**Recommendation:** the custom table. Post meta is fine for the small index status fields only.

## How it would work

1. A document is published (or replaced). The existing lifecycle action fires.
2. A new `document-library-search.php` listener records status Queued and schedules a WP-Cron job, following the Drive backup's pattern (single event, retries at increasing delays, failure shown in Review details with a Re-index button).
3. The job checks, at run time, that the post is still published and the attachment is still its current file and not in the private folder. If not, it stops and removes any rows.
4. It extracts text by type, one record per page or sheet, normalizes whitespace, and replaces that document's rows in one transaction.
5. If a PDF yields fewer than about 25 characters per page on average (threshold to be tuned in the spike), or specific pages are empty, it queues an OCR job for those pages.
6. The OCR job uploads to Drive, exports text, trashes the temporary Doc, and writes those pages with source `ocr`.
7. Search reads the table through the existing query functions; the REST endpoint and live filtering need no new route.

### Data contracts

Table `{$wpdb->prefix}glover_document_text`, InnoDB, utf8mb4:

| Column | Type | Notes |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED, primary key | |
| `post_id` | BIGINT UNSIGNED, indexed | The `document-library` post |
| `attachment_id` | BIGINT UNSIGNED | The file the text came from, so stale jobs can be detected |
| `part` | SMALLINT UNSIGNED | 0 = whole document (used for matching), 1 and up = page or sheet order |
| `part_label` | VARCHAR(190) | "Page 4", or the sheet name, or "" |
| `source` | VARCHAR(20) | `pdftotext`, `pdfparser`, `docx`, `xlsx`, `ocr` |
| `content` | MEDIUMTEXT, FULLTEXT index | Plain text, whitespace normalized |

The part 0 row holds the whole document's text so a search for "culvert Daniels" matches a document where the two words are on different pages. Page rows exist for snippets and page numbers. This roughly doubles the stored text; at an estimated 10,000 pages of about 3 KB each, the table would be around 60 MB including the index, which any MySQL host handles easily.

New system meta on the document (code-written, never edited by hand):

| Meta key | Purpose |
| --- | --- |
| `_glover_text_index_status` | `queued`, `indexed`, `ocr_queued`, `no_text`, `failed`, `excluded` |
| `_glover_text_index_note` | Error detail, method used, page count, OCR page count |
| `_glover_text_index_hash` | SHA-1 of the indexed file, so re-runs skip unchanged files |

One new ACF field, proposed: `document_exclude_from_text_search` (true/false, approvers only) for documents whose contents must not appear in snippets (see Risks, redactions). Excluded documents are still found by title.

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
- PHP cuts about 30 words around the first matched word, escapes it, and wraps matched words in `<mark>`.
- Shown under the title in the existing Title cell, for example: "Found on page 4: …approved the replacement of the culvert on Daniels Road at a cost of…".
- For PDFs the page label links to the file with `#page=4`. Most browser PDF viewers honor it; where they don't, the file simply opens at page 1, which is harmless.
- XLSX snippets name the sheet ("Found in sheet FY2027 Budget"). DOCX without page breaks says "Found in the document text".
- Snippets from OCR text add "(scanned page, text may contain errors)".
- Title-only matches show no snippet.

### Re-indexing and removal

| Event | Action |
| --- | --- |
| `document_published` | Queue indexing |
| `document_replaced` | Delete rows for the old attachment, queue the new file. Previous versions are private and never indexed |
| `document_unpublished` | Delete all rows, set status to empty |
| Trash or permanent delete of a document | Delete all rows (`wp_trash_post`, `before_delete_post`) |
| Settings or extractor change | WP-CLI or settings-screen "Re-index all", throttled like the backfill |

Defense in depth, so pending or private files can never leak: indexing happens only from these hooks; the job re-checks status and private storage when it runs; every search query also joins `wp_posts` with `post_status = 'publish'` (which `WP_Query` already does); and nothing reads the table except the published-only library query.

### Plugging into live filtering and the REST endpoint

No new endpoint and no change to `assets/library.js` beyond small copy. `glover/v1/documents` already returns the server-rendered `results` component, so snippets arrive with it. The `sort` filter gains a `relevance` value, which `glover_document_library_parse_filters()` and the sort labels must accept; `glover_document_library_url()` leaves it out of URLs when it's the default for a search. The existing typing pause before a live update stays, which keeps query load low.

## Accessibility considerations

The library's results must meet WCAG 2.1 AA (see [the accessibility requirements](../requirements/accessibility.md)).

- Snippets are plain text inside the existing Title cell, so the table structure and the stacked phone cards don't change.
- `<mark>` is not announced by most screen readers, so highlighting only adds emphasis. The "Found on page 4:" lead-in carries the meaning in text. Highlight colors come from `base/_colors.scss` and must keep text at 4.5:1 contrast. (SC 1.4.1, 1.4.3)
- Page links have unique accessible names, for example "Page 4 of Select Board minutes, 2026-03-04 (PDF)", using visually hidden text. (SC 2.4.4)
- The live region keeps announcing only the results count, so a screen reader isn't flooded with snippets; snippets are read when the user reaches each result. Focus behavior is unchanged. (SC 4.1.3)
- The OCR caveat is text, not an icon or color.
- Search makes scanned documents findable, but it doesn't make them accessible. A scanned PDF found by search still needs an accessible copy on request, and the "Request an accessible copy" link should be easy to reach from each result. OCR text must not be presented as a conforming alternate version.
- Help dialog copy is updated to explain that search looks inside documents, how to use quotes for phrases, and that very short words (under three letters) and common words aren't searched.
- Verification adds a keyboard and screen-reader check of results with snippets, and a 375px check.

## Backfill of legacy files

Bulk migration of the ~1,446 legacy files is a separate, later phase with its own sign-off. The backfill runs after it, or alongside it as each batch is imported.

1. **Text pass:** `wp glover documents index --missing --batch=50` (also available as a throttled WP-Cron loop for hosts without WP-CLI). Each document is skipped if its file hash is unchanged. With `pdftotext`, expect well under an hour for the whole set; with smalot, a few hours spread over cron runs.
2. **OCR pass:** only documents marked as needing OCR, at a deliberately slow rate (for example 100 pages an hour, run overnight) to stay well inside Drive API limits. Total time depends on the unknown scan count; if half the archive is scanned at five pages each, that is about 3,500 pages, or a few nights.
3. **Report:** the settings screen shows counts by status (indexed, OCR, no text, failed, excluded) and links to a filtered document list, so the clerk can see which old files remain unsearchable.
4. **Order:** current documents first (the ~120 that stay current), then newest to oldest.

## Phasing

Rough effort, to be firmed up after the spike.

- **S0, spike (about 1 day):** pick 50 representative legacy PDFs from the export (agendas, minutes, reports, across 2007 to 2026). Measure how many are scans, compare `pdftotext` and smalot on the born-digital ones, and test Drive OCR limits and page breaks through the API. Report the numbers before S1 starts.
- **S1, born-digital search (about 4 to 5 days):** table, extractors, background job and status, matching, ranking, snippets, help copy, the exclusion field, smoke tests, and doc updates.
- **S2, OCR (about 2 to 3 days):** Drive OCR, page splitting where available, OCR caveat, retries.
- **S3, backfill tooling (about 1 to 2 days):** WP-CLI command, throttled cron loop, status report. Run with the migration phase.

## Smoke-test checklist (for the build)

1. Publish a born-digital PDF. Confirm status Indexed, one row per page plus part 0, and that a word from page 3 finds it with a "page 3" snippet.
2. Search for a word that is in one document's title and another's body. Confirm the title match is first.
3. Submit a document and leave it Pending. Confirm none of its words can be found, and no rows exist.
4. Replace the document. Confirm old-file words no longer match and new-file words do.
5. Unpublish and trash a document. Confirm its rows are gone.
6. Publish a scanned PDF. Confirm OCR runs, the temporary Doc is trashed in Drive, and the snippet shows the OCR caveat.
7. Publish a DOCX and an XLSX. Confirm the snippet labels.
8. Mark a document as excluded. Confirm title search still finds it and its text doesn't.
9. Type MySQL operators (`+-<>()~*"@`) into the search box. Confirm no error.
10. Confirm category and topic counts reflect text matches.
11. With JavaScript off, confirm the same results through Apply filters.
12. Keyboard and screen-reader pass of results with snippets; 375px check.

## Known limitations and risks

- **Redactions.** If a PDF was "redacted" by drawing black boxes over text, the text is still in the file, and search snippets would show it on the public site. Real redaction removes the text. Mitigation: a line in the requirements guide, the exclusion field, and the approver's preview. This is the most serious risk.
- **Personal information becomes easier to find.** Minutes and reports may contain names, addresses or other details that were technically public but hard to find. Full-text search makes them easy to find. The town should be comfortable with that before launch.
- **Hosting.** Without shell access there is no `pdftotext` or page splitter: extraction uses smalot (slower, somewhat less accurate) and OCR loses page numbers. WP-Cron needs a real server cron on production, as for Drive backup.
- **MySQL FULLTEXT rules.** InnoDB ignores words under 3 characters by default (`innodb_ft_min_token_size`, not changeable on shared hosting), so "R1" or "VT" won't be found in body text. A built-in stopword list drops words such as "the", "will" and "about". Stopwords can probably be turned off for this table at index-creation time with a session setting; confirm in S1. MariaDB behaves slightly differently and should be tested if the host uses it.
- **OCR quality and limits.** Handwriting, stamps, faint copies and tables OCR poorly. Drive's PDF size and page limits through the API are not yet confirmed.
- **Page links.** `#page=` is a browser convention, not a standard; some viewers and all downloads ignore it.
- **Memory and time.** Very large PDFs (planning maps, scanned reports) can exceed PHP limits with smalot. Files over a set size or page count are marked `failed` with a note instead of retried forever.
- **Index drift.** If a file changes outside the library's hooks (manual upload replacement in wp-admin), the index goes stale until the hash check in a re-index pass catches it.
- **Drive dependency for OCR.** If Google settings are removed, OCR stops and those documents stay `ocr_queued`; born-digital search is unaffected.
- **Search help copy.** Jenny is writing the help copy; it needs a short addition for full-text search.

## Open questions

1. Is Jenny comfortable with the vendored smalot/pdfparser fallback, given the Google integration's "no libraries" rule, or should the build wait until hosting is chosen and require `pdftotext`?
2. Should the host decision weigh poppler support? It decides page-accurate OCR and extraction quality.
3. Should archived (pre-2028) documents be searchable by default? This proposal assumes yes.
4. Who can set "Exclude from text search": only the approver and backup approver, or submitters too?
5. Is the town (and Olivia) comfortable with personal details in old minutes becoming easy to find? Does Vermont's public records practice suggest any categories to exclude?
6. Should "Best match" be the default sort whenever there is a search, or should Most recent stay the default with Best match as an option?
7. Is OCR worth doing for the archive at all if the spike shows most scans are poor quality, or should it be limited to the ~120 current documents?

## Decisions needed for sign-off

- Approve the recommended architecture (custom FULLTEXT table, `pdftotext` with smalot fallback, Drive OCR).
- Approve the redaction mitigation and the exclusion field.
- Approve S0 (the spike) as the next step, with S1 to S3 confirmed after its numbers come back.

## Material change history

- 2026-10-20: Drafted this proposal for full-text search across Document Library files. Not yet approved.
