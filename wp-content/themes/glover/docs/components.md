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
- **Used on:** Documents, Submit a Document, Request an Accessible Copy, department and board pages, and `/sign-in/` (the Glover Google Workspace must-use plugin).

### Steps

- **Markup:** `components/common/steps.php` · **Styles:** `components/_steps.scss`
- **What it does:** A numbered row of short steps under a small label heading, with an optional note below a rule. Fresh number circles, Young Serif step titles; columns wrap on narrow screens. It's an ordered list, so screen readers announce each position.
- **Arguments:** `heading`, `steps` (each a `title` and a `text` that may hold links), optional `note` (may hold links).
- **Used on:** Submit a Document, signed out ("How It Works").

## Actions and Links

### Button

- **Styles:** `components/_button.scss` (classes on `<a>` or `<button>`)
- **What it does:** Pill buttons. `.button` plus a style: `--primary` (Forest Dark, one per view), `--secondary` (Teal), `--accent` (Orange), `--outline` (Forest Dark stroke), `--ghost` (Cream outline, on forest only). Sizes: default 44px, `--large` 52px (a form's submit or a panel's main action), `--compact` 36px (record rows, tables, filter bars). Loading state: `aria-busy="true"` with a `.button__spinner`; disabled state via `disabled` or `aria-disabled`.
- **Used on:** every page.

### Link Button

- **Styles:** `.link-button` in `components/_button.scss`
- **What it does:** A `<button>` that looks like a text link, for actions that don't navigate.
- **Used on:** the Documents filter panel (show all topics) and `/sign-in/` (send a new code).

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
- **Used on:** every public form (Request an Accessible Copy, `/sign-in/`, the Submit a Document sign-in panel, the Documents filters).

### Notice

- **Styles:** `.notice` in `components/_form.scss`; pair with `role="status"` or `role="alert"`
- **What it does:** A message box with a written title. `.notice` (Teal) informs, `--success` (Forest Dark) confirms, `--warning` (Orange) warns, `--emergency` (Alert fill) is an emergency, `--error` (Alert outline) reports form errors.
- **Used on:** Submit a Document (submitted and published messages), Request an Accessible Copy, `/sign-in/`.

### Chip

- **Styles:** `components/_chip.scss`
- **What it does:** Small pill labels for tags and active filters. `.chip-list` wraps them; `--active` is selected (Teal, Forest border); `--small` and `--outline` are display-only; `.chip__remove` is the × in a removable chip. A `.chip-toggle` checkbox followed by a `.chip` label makes a selectable chip that stays a real checkbox.
- **Used on:** Documents filters and active-filter rail.

### Badge and Status Label

- **Styles:** `components/_badge.scss`
- **What it does:** `.badge` is a 4px Teal file-type label (PDF, Word). `.status-label` is a written status such as Draft, never shown by color alone.
- **Used on:** Documents results.

### Dialog

- **Styles:** `components/_dialog.scss` · **Script:** `includes/features/document-library/assets/dialogs.js`
- **What it does:** Turns a `section[data-glover-dialog]` into a modal opened by any link with `data-dialog-open="<id>"`; Escape and Close return focus. Without JavaScript the section stays an ordinary part of the page. The dialog is the only thing with a shadow.
- **Used on:** Documents (search help) and Submit a Document (upload requirements).

## Document Library Parts

These live in `components/document-library/` and serve the Document Library feature. See [`docs/features/document-library.md`](features/document-library.md).

| Part | What it does | Used on |
|---|---|---|
| `filters.php` | The Refine panel: a plain GET form that `library.js` upgrades to live results. | Documents |
| `active-filters.php` | One removable chip per applied filter, plus Clear all. Links, so no JavaScript needed. | Documents |
| `summary.php` | The results count sentence. | Documents |
| `results.php` | Results table and pagination; stacks into cards on narrow screens. | Documents |
| `help.php` | Search help, shown as a dialog. | Documents |
| `requirements.php` | Accessibility and legal requirements for uploads, from the page chosen in settings, shown as a dialog. | Submit a Document |
| `latest-documents.php` | "Latest documents" feed for one board (`board_id`, `limit`). Styles: `components/_document-feed.scss`. | Department and board pages |
| `submit-sign-in.php` | The signed-out sign-in panel (email code form and Sign In With Google, posting to the must-use plugin's `/sign-in/`), followed by the Steps component. Page styles in `pages/_submit-document.scss`. | Submit a Document, signed out |
