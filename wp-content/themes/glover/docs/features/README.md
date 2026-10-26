# Glover Theme Feature Documentation

This directory is the orientation index for major Glover theme features. Update a feature document whenever its fields, data contracts, PHP, templates, components, styles, scripts, behavior, accessibility implications, dependencies, or approved scope change.

Every feature's public markup and styles follow the design standard in [`../design/spec-plate.html`](../design/spec-plate.html): colors, type, components and rules.

## Features

- [Accessibility Options](accessibility-options.md): An optional panel of visitor display preferences (text size, spacing, readable font, high contrast, color blindness mode, link highlighting) layered on top of the AA-conformant site, with its own on/off settings screen in wp-admin. Approved, not built.
- [Document Library](document-library.md): Public documents tagged by board, category, and topic, with an approval workflow, passwordless submitter sign-in, Google Drive backup, and full-text search inside PDF, Word and Excel files. Built and verified locally. [Full-text search proposal](document-search-proposal.md) (approved 2026-10-20, kept as the decision record).
- [Site Contacts](site-contacts.md): Users > Site Contacts, where an administrator picks which user is the Webmaster and which is the Town Clerk, with a helper that returns each. Built; the account emails name the Webmaster.
- [Login Screen](login-screen.md): The WordPress login in the Glover style: the shared sign-in panel (emailed code and Google) by default, with WordPress's password and reset screens restyled to match. Built.
- [User Screens](user-screens.md): The town's layout for Profile, Edit User and Add New User (a header and open sections), without the Admin Color Scheme picker, Keyboard Shortcuts, the About the User section or Application Passwords, and role menus limited to Administrator, Editor and Document Submitter. Built.
- [Event Calendar](event-calendar.md) — Separate Event and Meeting ACF models, ACF-managed Event Types, shared schedule behavior, recurrence and meeting records, validation, and public routing. Fields use native ACF presentation with a styled schedule-summary bubble.
