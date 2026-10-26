# Product

<!-- impeccable:product-schema 1 -->

Facts marked *(inferred)* come from the repository and Jenny's direction in the 2026-10-21 and 2026-10-22 sessions. She hasn't confirmed them in the Impeccable interview yet.

## Platform

web

## Users

- **Residents of Glover, Vermont** *(inferred)*, the primary audience. They come to the public site to find meetings, agendas and minutes, town documents, events, and how to get things done with the town office.
- **Town staff and board members** *(inferred)*, who sign in to post public records to the Document Library and use the same public styles to do it. The authenticated WordPress admin follows WordPress admin patterns and is outside these standards.

## Product Purpose

The official website of the Town of Glover, a public entity under ADA Title II. It replaces the legacy townofglover.com site with plain, findable public information: the calendar, meetings, the Document Library, boards and town services.

## Operating Context

- A WordPress theme (`wp-content/themes/glover/`). ACF Pro defines every post type, taxonomy and field group.
- Real content is entered on the local site and moved to the server at launch (`tools/README.md`).
- Sign-in for staff and board members is Google for townofglover.com accounts, or an emailed 6-digit code (`mu-plugins/glover-google/`).

## Capabilities and Constraints

- Feature specs live in `docs/features/`, and site-wide requirements in `docs/requirements/`.
- Styles are Sass in `src/styles/` (base, components, pages), compiled by Parcel. Features don't ship their own stylesheets.
- Every page uses one container: `$CONTAINER-WIDTH` and `$GUTTER` in `base/_core.scss`. No page sets its own max width.

## Brand Commitments

- Name: Town of Glover, Vermont.
- Copy uses Title Case for titles, headings, labels and buttons. It never uses em dashes. Interface copy never describes what the system did; it gives people only what they need to act.
- The visual reference is Jenny's "Glover.UI, Land & Water" interface kit canvas (https://claude.ai/artifact/Ejq4DmvXdXyNvE1wytXfZZ).

## Evidence on Hand

- The content inventory doc (https://claude.ai/code/artifact/b8a5fbad-4fe8-44f9-85bd-2ec3fd4a9883) and `project-files/townofglover-content-archive/` at the repo root.
- Real town facts in mock-ups (addresses, phone numbers, meeting times) must come from the inventory or the town. Never invent them.

## Product Principles

1. Residents find what they came for in seconds; structure and wording beat decoration.
2. Accessibility is a build requirement, not a later fix.
3. One set of standards for every page: shared tokens, container and components, no one-off styles.
4. Plain, neighborly language a resident can act on.

## Accessibility & Inclusion

WCAG 2.1 Level AA by 2028-04-26 (`docs/requirements/accessibility.md`). No overlays or accessibility widgets. Text contrast is at least 4.5:1 (3:1 for large text and UI borders), every control has a visible focus state, and every form field has a label.
