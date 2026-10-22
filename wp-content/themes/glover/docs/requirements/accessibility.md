# Accessibility Requirement (ADA Title II / WCAG 2.1 Level AA)

## Status

Drafted 2026-08-18, awaiting town approval. Not yet implemented, because the public frontend has not been built.

Last updated: 2026-10-20.

## The federal rule that applies

On 2024-04-24 the U.S. Department of Justice published a final rule under **Title II of the Americans with Disabilities Act** setting a technical standard for the web content and mobile apps of state and local governments. On 2026-04-20 DOJ issued an interim final rule extending the compliance dates by one year. The technical standard itself did not change.

**Technical standard: WCAG 2.1 Level AA.**

### Glover's compliance date is 2028-04-26

Deadlines are set by the population of the public entity:

| Entity | Deadline |
|---|---|
| Population 50,000 or more | 2027-04-26 |
| **Population under 50,000** | **2028-04-26** |
| Special district governments, any size | 2028-04-26 |

Glover has roughly 1,400 residents, so it falls in the under-50,000 group and the operative date is **2028-04-26**, not 2027. The 2027 date circulating in most published summaries is the large-entity date and does not apply here.

This is approximately 20 months out from the date of this document. It is not a reason to defer the work, for a reason specific to this project: **the public frontend is unstarted.** Building to standard now costs almost nothing. Retrofitting a finished site is where municipalities incur real expense.

### What is covered

All web content and mobile apps the town provides or makes available, including content produced by contractors and vendors on the town's behalf. This explicitly includes PDFs, videos, and images, not just HTML pages.

Note for this project: the legacy site embedded a third-party Google Calendar. A third-party embed the town chooses to place on its site is covered content. The unaffiliated-third-party exception below does not shelter it. This is an independent argument for the native calendar being built in `includes/features/event-calendar/`.

### The five exceptions

Content in these categories does not have to meet WCAG 2.1 AA:

1. **Archived web content.** Only if it was created before the compliance date, is kept solely for reference, research, or recordkeeping, lives in a clearly designated archive area, and has not been changed since archiving. All four conditions must hold.
2. **Preexisting conventional electronic documents.** Word, PowerPoint, PDF, or spreadsheet files that were already on the site before the compliance date and are not currently used to access town services.
3. **Third-party content not posted under contract.** Content posted by unaffiliated third parties, for example public comments. Content a third party posts *for* the town, and the tools that let third parties post, are still covered.
4. **Individualized password-protected documents.** Conventional documents about a specific person, property, or account, secured behind authentication.
5. **Preexisting social media posts.** Posts made before the compliance date.

Two limits on the exceptions that matter operationally:

- The exceptions are narrow and mostly cover the *past*. Anything published on or after 2028-04-26, and anything currently used to access town services, is in scope regardless of format.
- Even when content falls inside an exception, the town's general ADA obligations still apply: effective communication, reasonable modification, and equal opportunity to access services. An exempted document still has to be made available in an accessible form on request.

### Fundamental alteration and undue burden

The rule preserves the existing defense that an entity need not take action causing a fundamental alteration of its program or an undue financial and administrative burden. DOJ sets no numeric threshold and evaluates it entity by entity. Invoking it requires a documented determination by the responsible official, and the town must still provide access by other means. **This should be treated as a last resort for specific items, not as a posture for the site as a whole.**

### Conforming alternate versions

Serving a separate accessible version of an inaccessible page is permitted only in very limited circumstances, where technical or legal limitations prevent making the primary content accessible. A separate "accessible site" is not an acceptable general strategy.

## Why an overlay plugin is not the answer, cost aside

You raised accessiBe as out of reach on cost. The cost is real, but it is not the deciding factor: **overlay widgets do not produce compliance.**

- In January 2025 the Federal Trade Commission ordered accessiBe to pay **$1 million** to settle allegations that its marketing was deceptive. The FTC's complaint alleged the product failed to make basic components accessible, including navigation menus, form fields, and image descriptions. The order bars claims that an automated tool can make any website WCAG compliant absent competent and reliable supporting evidence.
- Courts and enforcers evaluate the actual accessibility of the delivered experience, not the presence of installed software. Sites running overlays have continued to draw complaints and litigation.

So the honest framing is not "we cannot afford the good solution, so we need a cheaper one." It is that **the paid overlay is not a solution**, and the standards-based approach is both the compliant path and the free one. The recurring cost of an overlay would buy a false sense of coverage.

## Approved scope

- The **public-facing town website** conforms to WCAG 2.1 Level AA by 2028-04-26.
- Conformance is achieved through the theme's own markup, styles, scripts, and editorial process. No third-party accessibility overlay, widget, or remediation service is used.
- Conformance is measured with optional enhancements switched off. The first-party [Accessibility Options](../features/accessibility-options.md) panel is a bonus layer above AA, not part of how the site conforms (see below).
- Accessibility is treated as a build requirement for every new feature, verified before merge, not as a remediation phase at the end.
- Public documents the town publishes on or after the compliance date are accessible, or an accessible equivalent is published alongside them.
- An accessibility statement page is published with a named contact and a feedback mechanism.

## Non-goals

- Conformance claims for the authenticated wp-admin staff editor. The existing event-calendar document already reasons this way: the staff editor is an internal employment tool. Staff accessibility needs are handled through Title I and Section 504 reasonable accommodation, separately and on request. This is a scoping decision, not a statement that staff needs do not matter.
- WCAG 2.2, or Level AAA. The federal standard is 2.1 AA. Meeting 2.2 where it is free to do so is welcome but is not a requirement.
- Retroactive remediation of the entire legacy document archive. See the phased approach below.
- Automated conformance monitoring services.

## Requirements for the theme build

These are binding on `wp-content/themes/glover`. WCAG success criteria are cited so each item is traceable.

### Structure and navigation

- Every page uses one `<main>` landmark, plus `<header>`, `<nav>`, and `<footer>` landmarks. `header.php` wraps the logo and main menu in `<header class="site-header">`, and every template renders exactly one `<main id="main-content" tabindex="-1">`. New templates must do the same. (SC 1.3.1, 2.4.1)
- A visible-on-focus "Skip to main content" link is the first focusable element. It is the first child of `<body>` in `header.php` and targets `#main-content`. Its `.skip-link` styles (visually hidden until focused) live in `src/styles/base/_core.scss`, so the link shows permanently until `dist/` is built. (SC 2.4.1)
- Headings descend without skipping levels, and each page has exactly one `<h1>`. (SC 1.3.1, 2.4.6)
- Every page has a unique, descriptive `<title>`. The theme already declares `add_theme_support('title-tag')`. (SC 2.4.2)
- `<html lang>` is set. `header.php` already emits `language_attributes()`. (SC 3.1.1)
- Link text is meaningful out of context. No bare "click here" or "read more" without an accessible name that identifies the destination. (SC 2.4.4)

### Keyboard and focus

- All interactive elements are reachable and operable by keyboard, in a logical order, with no keyboard traps. (SC 2.1.1, 2.1.2, 2.4.3)
- A visible focus indicator is present on every focusable element. **Never remove outlines without an equivalent replacement.** (SC 2.4.7) The ring is 3px: Fresh Dark (`--color-fresh-dark`, 3.2 to 4.4:1) on light grounds and Orange (`--color-orange`) on forest grounds, as the spec plate's Focus Rule sets out.
- Menus, modals, and dropdowns manage focus correctly. The stock `glover-modal` and `glover-dropdown` base-theme components are unreviewed for this and must be audited before first use, not assumed correct.
- Interactive controls are real `<button>` and `<a>` elements. A `<div>` with a click handler is not acceptable. (SC 4.1.2)

### Color and visual design

- Text contrast is at least 4.5:1, or 3:1 for large text at 24px, or 19px bold. (SC 1.4.3)
- UI component boundaries and meaningful graphics meet 3:1. (SC 1.4.11)
- Every color and the grounds it may sit on are set in the design standard, [`../design/spec-plate.html`](../design/spec-plate.html), with each pairing's ratio. Fresh and Orange are never text on light grounds; Link Green, Alert and Muted are never text on forest grounds.
- Color is never the sole means of conveying information. This has a direct calendar consequence: **a cancelled occurrence must not be indicated by color alone.** It needs a text label such as "Cancelled". (SC 1.4.1)
- Content reflows without horizontal scrolling at 320 CSS pixels wide, equivalent to 400% zoom at 1280px. (SC 1.4.10)
- Content survives user text-spacing overrides. (SC 1.4.12)
- Text can be resized to 200% without loss of content or function. Use relative units in `src/styles`. (SC 1.4.4)
- Content revealed on hover or focus is dismissible, hoverable, and persistent. (SC 1.4.13)
- The site works in both orientations. (SC 1.3.4)

### Images and media

- Every meaningful image has a meaningful `alt`. Decorative images take `alt=""`. (SC 1.1.1)
- ACF image fields render alt text from the media library. `components/common/glover-acf-image.php` and `glover-single-image.php` must be reviewed to confirm they emit `alt`, and the editorial process must require alt text at upload time.
- Prerecorded video, for example meeting recordings, requires accurate captions. (SC 1.2.2)
- Prerecorded video requires audio description or a media alternative where visual information is not conveyed in the audio. (SC 1.2.3, 1.2.5)
- No content flashes more than three times per second. (SC 2.3.1)
- Any auto-playing, moving, or scrolling content lasting more than five seconds can be paused. This constrains use of the base theme's GSAP animations and `glover-reveal-animations`. (SC 2.2.2)

### Forms

- Every input has a programmatically associated visible label. (SC 1.3.1, 3.3.2)
- Errors identify the field in text and explain how to correct it. (SC 3.3.1, 3.3.3)
- Inputs collecting user information declare `autocomplete`. (SC 1.3.5)
- Dynamic status messages are announced without moving focus, using `aria-live`. The event-calendar admin summary already models this correctly. (SC 4.1.3)

### Code quality

- Markup parses cleanly, with no duplicate `id` values. (SC 4.1.1)
- Name, role, and value are exposed for all UI components. Prefer native HTML over ARIA. Incorrect ARIA is worse than none. (SC 4.1.2)

### Documents, the highest-risk area for this town

Municipal sites fail Title II on PDFs more than on anything else, and this project has a structural exposure: the [Document Library](../features/document-library.md) holds **agendas, minutes, and supporting documents**, which are exactly the documents residents need and exactly the ones that get posted as scanned PDFs. Meetings no longer store documents themselves.

- **Preferred: publish agendas and minutes as HTML.** HTML is accessible by default, indexable, linkable, and free. A scanned PDF is none of those. The Document Library currently accepts files only (PDF, DOCX, XLSX); an HTML path for agendas and minutes is not built.
- A scanned image of a document is never acceptable on its own. It is unreadable to a screen reader and has no text layer.
- Where a PDF must be posted, it needs real text, a document title, a language, tagged structure, and heading order. Word's "Check Accessibility" plus a correct export to PDF handles most of this at no cost.
- Link text states the format and, where useful, the size. Example: "Select Board minutes, 2026-03-04 (PDF, 240 KB)".
- Uploaded documents do not become accessible merely because they are linked. The Document Library asks submitters to confirm accessibility, links a requirements guide from the submission page, and offers a public request form for accessible copies.

## Beyond AA: Accessibility Options

The site will also offer an [Accessibility Options](../features/accessibility-options.md) panel: a button on every public page that lets a visitor choose larger text, wider spacing, an easy-to-read font, high contrast, a color blindness mode, and link highlighting. It is approved as a bonus on top of AA, and these rules bind it:

- **Every page meets AA without it.** Templates are built and verified to this document with the feature switched off. Nothing AA requires may depend on the panel; for example, text must still resize to 200% through the browser, and contrast must pass in the default palette.
- **A failure is fixed at its source.** If a page fails AA, the fix goes in that page's markup or styles, never in a panel mode.
- **The panel and its modes are in scope.** The panel is public content and must itself meet AA, and pages must still meet AA with any combination of its settings on.
- **It is not an overlay.** It is theme code, it changes only presentation the visitor asks for, and it never alters markup, reading order, or what assistive technology announces. It does not change the conclusion in "Why an overlay plugin is not the answer".
- **It can be switched off.** An administrator can turn the whole feature, or any one setting, off in wp-admin. Switching it off must never reduce conformance.
- **The accessibility statement describes it as an extra,** not as a conformance measure.

## Achieving this without a paid plugin

Every tool below is free.

### Testing tools

- **axe DevTools** browser extension, and **WAVE** browser extension. Between them these catch a large share of programmatically detectable issues.
- **Lighthouse**, built into Chrome DevTools, for a fast per-page accessibility score.
- **Keyboard-only pass.** Unplug the mouse and use Tab, Shift+Tab, Enter, Space, and arrow keys. This is free and finds what automated tools cannot.
- **Screen reader pass.** VoiceOver ships with macOS, Command+F5. NVDA is free on Windows. Testing the top ten pages is realistic for a town this size.
- **TPGi Colour Contrast Analyser**, free, for checking the palette before it is committed to `src/styles/base/root.scss`.
- **W3C HTML validator**, for SC 4.1.1.
- **400% zoom check**, browser zoom or a 320px-wide viewport, for SC 1.4.10.

Automated tools detect roughly a third of WCAG issues. The manual keyboard and screen reader passes are not optional extras; they are where the remaining failures are found.

### Optional build integration

`pa11y-ci` or `@axe-core/cli` are free npm packages that can run against local URLs and fail on regressions. This is a genuine option given the theme already has an npm pipeline, but it is a convenience, not a requirement, and it should not be added until the frontend exists.

### Process, which matters more than tooling

1. Name a responsible person. DOJ's own first-steps guidance leads with this.
2. Train whoever posts content. Most ongoing failures are editorial, not technical: missing alt text, "click here" links, untagged PDFs, uncaptioned video.
3. Inventory what the town publishes and where.
4. Prioritize by use. Agendas, minutes, meeting times, contact information, and forms come before decorative pages.
5. Publish an accessibility statement with a contact and a feedback route.
6. Check accessibility as part of building each feature, using this document's checklist, rather than auditing at the end.

## Phased approach

Sequenced to fit the project's actual state rather than an abstract timeline.

**Phase 1, now, during theme build.** Bake the structural requirements into `header.php`, `footer.php`, base styles, and every new component. Choose an accessible color palette before it is committed. Cost: zero, because this code is being written anyway.

**Phase 2, with the public calendar.** The calendar is the most interactive planned surface and the highest-risk one. Build its keyboard behavior, status announcements, and non-color status indicators correctly the first time.

**Phase 3, content migration.** As legacy content moves from `townofglover-wordrpress-export.xml`, apply the standard. Migration is the cheapest moment to fix alt text, heading structure, and link text.

**Phase 4, documents.** Establish the HTML-first agenda and minutes workflow. Decide what goes to a designated archive area, which then qualifies for the archived-content exception, and what stays current and must be made accessible.

**Phase 5, before 2028-04-26.** Full manual audit, screen reader pass, publish the accessibility statement, document any undue-burden determinations.

## Verification

- Automated scan of every page template with axe DevTools or WAVE, zero violations.
- Keyboard-only traversal of each template.
- Screen reader pass on the highest-traffic pages.
- Contrast check of the committed palette.
- 400% zoom and 320px reflow check.
- HTML validation.
- Manual document spot-check on published agendas and minutes.

Record results per feature in that feature's document, in the style already used by `../features/event-calendar.md`.

## Known limitations and risks

- No conformance work has been done yet, because no public frontend exists. This document is a requirement, not a status report.
- The base theme's stock components in `components/common/` and `src/js/components/common/` are unaudited. They are agency scaffolding, not verified accessible code, and must not be assumed compliant.
- The legacy document backlog is large. The Aug 10, 2026 export holds 1,446 document files (1,429 PDFs and 17 Word, RTF, ODT or PowerPoint files, from 2007 on). About 1,230 are meeting agendas and minutes; about 210 are town reports, ordinances, bylaws, policies, plans and studies. The live site added 56 more posts between Aug 10 and Oct 16, 2026, at a rate of roughly 4 to 5 documents a week. The content inventory proposes a split: about 120 files stay current and must be made accessible (ordinances, bylaws, policies, current plans, the last two town reports, forms, the current year's agendas and minutes), and about 1,300 go to a designated archive area. These counts come from file titles, not file-by-file review, and how many PDFs are scanned images without a text layer is still unknown. See `project-files/townofglover-content-archive/` at the repo root.
- GSAP animation and reveal effects, if used, carry motion and pause obligations.
- Vermont may impose state-level obligations beyond the federal floor. Not researched here.
- WCAG 2.1 AA conformance reduces but does not eliminate complaint risk. Nothing produces immunity.
- This document is engineering guidance based on DOJ's published rule and guidance. It is not legal advice, and the town should have counsel confirm its obligations.

## Open questions

- Who is the named responsible person for accessibility?
- Will agendas and minutes move to HTML, or remain PDF? This single decision drives most of the ongoing effort.
- Are meetings recorded and posted as video? If so, captioning has a real recurring cost and needs a budget line.
- Which legacy content moves to a designated archive area, and which stays current?
- Does the town want an accessibility statement page in the first launch scope?
- Is there budget for one external audit before the deadline?

## Sources

- DOJ, Small Entity Compliance Guide, https://www.ada.gov/resources/small-entity-compliance-guide/
- DOJ, First Steps Toward Complying, https://www.ada.gov/resources/web-rule-first-steps/
- DOJ interim final rule extending compliance dates, published 2026-04-20, Federal Register 2026-07663.
- FTC action against accessiBe, January 2025.

## Material change history

- 2026-10-22 - Pointed color and focus requirements at the design standard, `docs/design/spec-plate.html`. The focus ring is now Fresh Dark on light grounds and Orange on forest grounds (replacing blue and gold), checked to 3:1 or better on every ground.

- 2026-10-20 - Added "Beyond AA: Accessibility Options": the approved first-party preferences panel is a bonus layer, conformance is measured with it switched off, and it must itself meet AA.
- 2026-10-19 - Added the skip link, `<header>` landmark, and a single `<main id="main-content">` on every template, and gave `index.php` its missing header and footer. Deleted the base theme's blank `templates/index.html`, which had made WordPress treat the theme as a block theme and render plain pages and posts empty; the classic PHP template hierarchy now applies. Verified by curl across the front page, pages, a post, search, 404, the Documents archive, both Document Library forms, and board pages, and by a keyboard pass on a page.
- 2026-10-19 - Pointed the Documents section at the Document Library, since Meetings no longer store agendas, minutes, or supporting documents, and noted that no HTML path for agendas and minutes exists yet.
- 2026-08-18 - Created the accessibility requirement. Established WCAG 2.1 AA and the 2028-04-26 deadline, recorded the DOJ 2026 one-year extension, rejected overlay widgets as a compliance strategy, and set the no-plugin standards-based approach.
- 2026-10-16 - Quantified the legacy document backlog from the content inventory (1,446 files, about 120 current and about 1,300 proposed for archive) and replaced the "unquantified" limitation.
