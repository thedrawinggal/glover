# Glover Components

Every reusable piece of the public site: what it is, where it's used, and how to call it. Visual standards (colors, type, sizes, states) live in the spec plate, [`docs/design/spec-plate.html`](design/spec-plate.html); this catalog is about the code.

## Rules

- Check this catalog before building anything. If a component here does the job, use it. If it almost does, extend it with an option rather than writing a second, similar one.
- A new component, or a change to one's options, updates this file in the same change.
- Markup components are PHP template parts under `components/`, called with `get_template_part($slug, null, $args)`. Each one documents its `$args` in its header comment. Styles are Sass partials in `src/styles/components/`, one per component, imported in `src/styles/app.scss`.
- Page-only styles go in `src/styles/pages/`, never in a component partial. A piece used on more than one page belongs here, not in a page file.
- Every color comes from `--color-*` in `base/_colors.scss`; every page container uses `$CONTAINER-WIDTH` and `$GUTTER` from `base/_core.scss`.
- The stock base-theme parts (`components/common/glover-*.php`, `src/js/components/common/*`) are unaudited and unused; they are not Glover components. Don't build on them without checking accessibility first.

## Page Structure

### Page Banner

- **Markup:** `components/common/page-banner.php` · **Styles:** `components/_page-banner.scss`, `components/_breadcrumb.scss`
- **What it does:** The Forest band at the top of every interior page: breadcrumb (label style, Orange links, Fresh arrows), the page's only h1, and a right-hand area that sits beside the title from 992px and drops below it on narrower screens.
- **Arguments:** `title`, `breadcrumbs` (label and url pairs; the last is the current page), optional `intro` line, `actions` (any number of buttons: the first Teal, the rest Cream outline, or set `variant` to `secondary` or `ghost`), `aside` (any other right-hand content: a callable that prints it, or an HTML string), `note` (a bold line across the bottom).
- **Used on:** Documents, Submit a Document, and department and board pages.

### Steps

- **Markup:** `components/common/steps.php` · **Styles:** `components/_steps.scss`
- **What it does:** A numbered row of short steps under a small label heading, with an optional note below a rule. Fresh number circles, Young Serif step titles; columns wrap on narrow screens. It's an ordered list, so screen readers announce each position.
- **Arguments:** `heading`, `steps` (each a `title` and a `text` that may hold links), optional `note` (may hold links).
- **Used on:** Submit a Document, signed out ("How It Works").

### Sign-In Panel

- **Markup:** `components/common/sign-in-panel.php`, with its left side in `components/common/sign-in-intro.php` · **Styles:** `components/_sign-in-panel.scss`, with the on-forest form styles in `components/_form.scss`
- **What it does:** A Forest Dark feature panel: an intro on the left (icon, optional label, heading, intro line, Need access line) and a Forest card on the right that walks through sign-in. The left side never changes; only the card does, and every step comes back to the page the panel is on. Card states: start (email box, then Sign In With Google); code sent or resent (code box, then Send a New Code, Use a Different Email Address, Sign In With Google Instead); wrong code; code expired; too many wrong codes; email not valid; too many requests; account can't sign in; Google problem; page timed out. Forms post to the Glover Google Workspace must-use plugin's `/sign-in/` address; without the plugin the card is a single Sign In link.
- **Arguments:** `label` (small label above the heading), `heading`, `heading_level` (2, or 1 when the panel is the page's main heading), `intro`, `access` (HTML, may hold links), `icon` (`document`, `lock`, or `logo`: a dashed placeholder where the town logo will go), `return` (where a finished sign-in goes), `back` (the page the panel is on; defaults to `return`), `start_links` (extra links under the start card, as label and url pairs). The step, status and code address come from the request (`glover_step`, `glover_status`, the plugin's sign-in session) unless `step`, `status` and `email` are passed.
- **Used on:** Submit a Document, signed out (left side from `glover_document_library_sign_in_panel_args()`), and the WordPress login's default screen (`includes/features/login-screen/`, with the logo placeholder, the label, an h1 and Sign In With Your Password Instead). WordPress's own login screens print the same left side through `sign-in-intro.php`.

## Actions and Links

### Button

- **Styles:** `components/_button.scss` (classes on `<a>` or `<button>`)
- **What it does:** Pill buttons. `.button` plus a style: `--primary` (Forest Dark, one per view), `--secondary` (Teal), `--accent` (Orange), `--outline` (Forest Dark stroke), `--ghost` (Cream outline, on forest only). Sizes: default 44px, `--large` 52px (a form's submit or a panel's main action), `--compact` 36px (record rows, tables, filter bars). `--icon` makes it an icon-only circle as wide as it is tall; keep the words in a `.visually-hidden` span and a `title`, as the Documents table's Download does (`glover_document_library_download_link()` with `$icon`). Loading state: `aria-busy="true"` with a `.button__spinner`; disabled state via `disabled` or `aria-disabled`.
- **Used on:** every page.

### Link Button

- **Styles:** `.link-button` in `components/_button.scss`
- **What it does:** A `<button>` that looks like a text link, for actions that don't navigate. On Forest it is Cream and, like every Cream link on Forest, turns Orange on hover: use the `cream-link-hover` mixin (`base/mixins.scss`) for any new one.
- **Used on:** the Documents Filters panel (show all topics) and the Sign-In Panel (Send a New Code).

### Breadcrumb

- **Styles:** `components/_breadcrumb.scss`
- **What it does:** An ordered list in `nav[aria-label="Breadcrumb"]` with arrows between items; the current page carries `aria-current="page"`. The Page Banner restyles it for forest.

### Pagination

- **Styles:** `components/_pagination.scss`
- **What it does:** Numbered page links inside a labelled `nav`; the current page has `aria-current` and `.pagination__current`.
- **Used on:** Documents results.

## Forms and Feedback

### Form Fields

- **Styles:** `components/_form.scss`
- **What it does:** `.form-field` wrapper, `.form-label` (bold), `.form-help` (muted help under the label, tied with `aria-describedby`), `.form-error` (Alert, above the field), `.form-input` and `.form-select` (48px, white, 8px corners; Forest border on focus, Alert border with `aria-invalid`), `.form-required`, `.form-choice`, and `.check-list` (checkboxes with an optional `.count`).
- **States:** every field is 48px, white, 1px Border, 8px corners, closed, focused, open, chosen or in error, and never changes size: focus adds a 2px Forest border (a 1px inset shadow over the 1px border) and the Fresh Dark ring; errors do the same in Alert; disabled is Surface Alt with a Line border. Selects (`.form-select`, ACF selects and Select2 boxes) are the same field with a Forest chevron, and their list opens as a separate 8px panel 4px below. The rules are Sass mixins in `_form.scss` (`field`, `field-focus`, `field-invalid`, `field-disabled`, `field-select`), so any new form uses them instead of its own.
- **ACF forms:** any `acf_form()` on the public site (class `.acf-form`) gets these fields with no styles of its own: labels and help, text boxes, selects, Select2, radio lists as choice cards, checkbox lists as wrapping pill chips, the basic file uploader as a dashed drop area with a Primary button, side-by-side true/false fields in one white box, Alert error text above each field and an Alert-outlined notice above the form. Fields given a width in ACF share a row and wrap on narrow screens (Month, Day and Year); a group's sub fields have no box of their own. Don't use ACF's date picker on the public site: its calendar fails AA, so dates are Month, Day and Year fields.
- **On forest:** add `.on-forest` to a Forest or Forest Dark container. Errors turn Orange (message text, a 2px field border, `.notice--warning` for card-level errors), help text Teal, links Fresh and the focus ring Orange, because Alert fails there.
- **Used on:** every public form (the Sign-In Panel, the Submit a Document form, the Documents filters).

### Notice

- **Styles:** `.notice` in `components/_form.scss`; pair with `role="status"` or `role="alert"`
- **What it does:** A message box with a written title. `.notice` (Teal) informs, `--success` (Forest Dark) confirms, `--warning` (Orange) warns, `--emergency` (Alert fill) is an emergency, `--error` (Alert outline) reports form errors.
- **Used on:** Submit a Document (submitted and published messages) and the Sign-In Panel.

### Chip

- **Styles:** `components/_chip.scss`
- **What it does:** Small pill labels for tags and active filters. `.chip-list` wraps them; `--active` is selected (Teal, Forest border); `--small` and `--tag` are display-only (`--tag` is a topic in a results row: Teal fill, no border, Forest Dark text); `.chip__remove` is the × in a removable chip; `--filter` is an applied filter (Forest, Cream text and ×, Forest Dark on hover). A `.chip-toggle` checkbox followed by a `.chip` label makes a selectable chip that stays a real checkbox.
- **Used on:** Documents filters and active-filter rail.

### Badge and Status Label

- **Styles:** `components/_badge.scss`
- **What it does:** `.badge` is a 4px Line Subtle file-type label (PDF, Word). `.status-label` is a written status such as Draft, never shown by color alone.
- **Used on:** Documents results.

### Dialog

- **Styles:** `components/_dialog.scss` · **Script:** `includes/features/document-library/assets/dialogs.js`
- **What it does:** Turns a `section[data-glover-dialog]` into a modal opened by any link with `data-dialog-open="<id>"`; Escape and Close return focus. Without JavaScript the section stays an ordinary part of the page. The dialog is the only thing with a shadow.
- **Used on:** Documents (search help) and Submit a Document (upload requirements).

## Document Library Parts

These live in `components/document-library/` and serve the Document Library feature. See [`docs/features/document-library.md`](features/document-library.md).

| Part | What it does | Used on |
|---|---|---|
| `filters.php` | The Filters panel: a plain GET form that `library.js` upgrades to live results. A sidebar from 992px; below that, a full-screen panel opened by the Filters button, with Close, Clear All and Show N Results. | Documents |
| `active-filters.php` | One removable chip per applied filter, plus Clear all. Links, so no JavaScript needed. | Documents |
| `summary.php` | The results count sentence. | Documents |
| `results.php` | Results table and pagination. From 1200px a table with a Surface Alt header row (column names in the label style, Muted) and each result's topics as tags under its title; below that, list rows (date and type in the label style, title, board in Muted, a 44px Download circle). Search snippets sit in a row under each result. | Documents |
| `help.php` | Search help, shown as a dialog. | Documents |
| `requirements.php` | Accessibility and legal requirements for uploads, from the page chosen in settings, shown as a dialog. | Submit a Document |
| `latest-documents.php` | "Latest documents" feed for one board (`board_id`, `limit`). Styles: `components/_document-feed.scss`. | Department and board pages |
| `submit-aside.php` | The signed-in right-hand column: account with Sign Out and assigned boards, the Upload Requirements panel (opens the requirements dialog) and Questions? (the Town Clerk). From 992px it stays pinned in view as the form scrolls (on screens at least 720px tall). Styles in `pages/_submit-document.scss`. | Submit a Document, signed in |
| `submit-sign-in.php` | The Sign-In Panel with the submitters' left side, followed by the Steps component (How It Works). | Submit a Document, signed out |
