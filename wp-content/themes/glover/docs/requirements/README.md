# Glover Site Requirements

This directory holds site-wide requirements that apply across features. Feature-specific behavior lives in `../features/`.

A requirement here is binding on every feature unless a feature document records an approved, reasoned exception.

The site's visual standard (colors, type, components and their rules) is the spec plate, [`../design/spec-plate.html`](../design/spec-plate.html).

## Requirements

| Requirement | Status | Document |
|---|---|---|
| Accessibility (ADA Title II / WCAG 2.1 AA) | Drafted, awaiting approval | [accessibility.md](accessibility.md) |
| Browser and device support | Not yet defined | - |
| Performance budget | Not yet defined | - |
| Content model and information architecture | Partially defined in feature docs | - |
| Privacy, analytics, and tracking | Not yet defined | - |
| Hosting, backup, and disaster recovery | Not yet defined | - |
| Public records retention | Not yet defined | - |
| Security and access control | Not yet defined | - |

The "Not yet defined" rows are placeholders that name known gaps. They are deliberately empty rather than filled with assumed values. Do not treat an empty row as "no requirement"; treat it as a decision the town has not made yet.

## Conventions

Requirements documents follow the same house style as feature documents:

- State approved scope and explicit non-goals.
- Record data contracts, file maps, and verification steps.
- Record known limitations and open questions honestly rather than omitting them.
- Append dated entries to a material change history.

## Change history

- 2026-08-18 - Created the requirements index and the accessibility requirement.
