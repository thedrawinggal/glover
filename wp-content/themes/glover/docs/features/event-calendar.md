# Event Calendar

## Status

ACF data-contract and schedule-editor stage. The Event and Location post types, private Organizer taxonomy, event fields, recurrence exceptions, occurrence-level meeting records, schedule validation, Google Calendar-inspired Event editing experience, and basic permalink routing exist. Public and admin calendar views do not exist yet.

Last updated: 2026-08-03.

## User need and purpose

Residents need one current schedule of public meetings, hearings, municipal deadlines, closures, programs, and community events. Town staff need a familiar, plain-language editing flow that lets them enter repeating schedules once while maintaining public notice status, agendas, minutes, cancellations, moved locations, and rescheduling for individual occurrences.

The Phase 1 content source recommends `/calendar/` as the public destination. The previous site used `/calendar-of-events/` and an embedded Google calendar with little explanatory content.

## Approved current scope

- ACF Local JSON for the `event` and `location` post types and the `organizer` taxonomy.
- One required Event Type select with the values Meeting, Program, Community Event, and Closure.
- One Schedule tab organized with static ACF heading-and-description fields without adding data nesting or collapsible controls.
- One-time, all-day, multi-day, preset repeating, custom repeating, and selected-date schedules.
- Google Calendar-style custom repeat controls for interval, unit, weekdays, monthly rule, and series ending.
- Event-only admin presentation that keeps the date range, times, All day checkbox, and Repeat control together; uses circular weekday toggles; keeps conditional recurrence controls inline beneath static section headings; and styles changed-date rows consistently with the primary schedule controls.
- A live, plain-language Schedule summary that describes dates, times, recurrence frequency, selected weekdays or monthly rule, and series ending without saving duplicate schedule data.
- Series-level changes for cancelled, rescheduled, moved, and noted occurrences.
- Occurrence-level public notice status, agenda, and minutes for recurring meetings.
- Explicit file-versus-external-link selection for agendas, minutes, supporting documents, and landing-page documents.
- Validation for chronological custom-repeat end dates and additional selected dates.
- `/calendar/` as the Event archive slug.
- No public Organizer taxonomy archives.
- Non-landing-page Event links and direct requests resolve to the calendar archive.

## Current non-goals

- Public month, week, or agenda/list views.
- Admin calendar view.
- Occurrence generation and exception expansion.
- Event landing-page template.
- iCal feeds, homepage widgets, print schedules, reminders, notifications, permissions, duplication, or final styling.

## Source evidence

- `project-files/townofglover-content-archive/PHASE-1-CONTENT-AUDIT.md`, Page 12 — Calendar.
- `project-files/townofglover-content-archive/pages/calendar-of-events/index.md`.
- Original event-calendar and municipal workflow requirements supplied by the repository owner on 2026-08-03.
- Google Calendar event editor screenshot supplied by the repository owner on 2026-08-03 as the editing-model reference.

## Data and field contracts

### Event records

- Post type: `event`.
- Public archive: `/calendar/`.
- Common required fields: `event_type`, `event_organizer`, `event_location`, `short_description`, `start_date`, and `recurrence_pattern`.
- Event Type remains a select with `meeting`, `program`, `community_event`, and `closure`.
- One Organizer term is required per event.
- `has_landing_page` determines whether an Event owns a standalone public page. When false, generated and manually entered Event URLs resolve to `/calendar/?event_id=<post-id>`.

### Schedule editing structure

Schedule fields remain on the Schedule tab in this order:

1. Date, time, All day, and Repeat in one primary schedule section.
2. Custom recurrence, shown only for Custom.
3. A generated Schedule summary, placed after the controls that define the active schedule.
4. Selected dates, shown only for Selected dates.
5. Changes to individual dates, shown for every repeating schedule.

The headings are non-interactive native ACF message fields enhanced to semantic headings in the Event editor. They do not add group prefixes or otherwise nest the saved event data. Meeting records by date is located on Meeting Details and shown only for repeating meetings. The summary is an admin-only live output derived from the current native ACF values; it is not saved as another field.

### Dates and repeat patterns

- One-time events use `start_date`, optional `end_date`, `start_time`, and optional `end_time` with `recurrence_pattern` set to `none`.
- `all_day` hides and removes the requirement for start and end times.
- `start_date` is the first occurrence date for every schedule. There is no duplicate recurrence start field.
- Repeat choices are `none`, `daily`, `weekly`, `monthly`, `annually`, `weekdays`, `custom`, and `selected_dates`.
- Daily, Weekly, Monthly, Annually, and Every weekday are simple presets based on the Start Date. Weekly uses its weekday; Monthly uses its weekday position in the month; Annually uses its month and day; Every weekday uses Monday through Friday.
- Weekly, Monthly, and Annually labels update from the Start Date, for example “Weekly on Tuesday,” “Monthly on the first Tuesday,” and “Annually on August 4.”
- When an editor first chooses a custom weekly schedule, the Start Date's weekday is selected automatically if no weekdays have been chosen; editors may then add or remove days.
- Custom uses `recurrence_interval` plus `recurrence_unit` (`day`, `week`, `month`, or `year`). The controls read as a sentence such as “Repeat every 2 weeks.”
- Custom weekly schedules require one or more `recurrence_days` values.
- Custom monthly schedules require `recurrence_monthly_basis`: `day_of_month` or `weekday_position`, both derived from the Start Date.
- Custom endings use `recurrence_end_type`: `never`, `on_date`, or `after_count`. `on_date` requires `recurrence_end_date`; `after_count` requires `recurrence_end_count` and counts the Start Date as the first occurrence.
- Selected-date schedules use the Start Date as the first occurrence and `selected_dates` for one or more additional, later, unique dates.
- Future occurrence generation must always use a bounded requested range so a never-ending series cannot create an infinite loop.

### Changes to individual dates

- Field: `occurrence_exceptions`.
- Each row targets one scheduled `date` without changing the base repeat rule.
- Supported statuses remain `cancelled`, `rescheduled`, `moved`, and `note`.
- A rescheduled row requires `new_date` and may override start/end time.
- A moved row requires `new_location` and may include a public note.
- One row currently represents one change type. Combining a move and reschedule for the same occurrence is not supported in this stage.

### Recurring meeting records

- Field: `occurrence_meeting_records`.
- Visible only when `event_type` is `meeting` and `recurrence_pattern` is not `none`.
- Each row attaches `warning_status`, `agenda`, and `minutes` to one scheduled `date`.
- These rows do not create calendar occurrences. The repeat schedule remains the source of dates, and the future occurrence engine must merge the matching row into the generated occurrence.
- Top-level `warning_status`, `agenda`, `minutes`, and `supporting_documents` are limited to non-repeating meetings to avoid competing sources.
- Meeting mode, virtual meeting link, organizer, location, contact, and short description remain series-level defaults.

### Document source selection

- Agenda and minutes groups use a required `source` value: `none`, `file`, or `url`.
- Repeater document rows use a required `source` value: `file` or `url`.
- The selected source is authoritative.
- Selecting a file or URL makes that visible field required. `none` is available for agenda and minutes that have not been posted.
- Uploaded public documents do not become accessible merely because they are linked. Editors must confirm document accessibility or provide an equivalent accessible HTML path before publication.

### Organizers and locations

- Taxonomy: `organizer`; it is flat, available in wp-admin and REST, but is not public or publicly queryable and has no public rewrite.
- Organizer represents the town body, office, service, or community organization responsible for an event.
- Organizer colors are supplemental metadata and must never be the only way an organizer or status is identified.
- Post type: `location`; it is admin-only and stores reusable address details. A virtual location may intentionally have no street address.

## File map

- `acf-json/post_type_event.json` — Event registration, Organizer attachment, and `/calendar/` archive.
- `acf-json/post_type_location.json` — reusable, non-public Location registration.
- `acf-json/taxonomy_organizer.json` — admin-visible, non-public Organizer taxonomy.
- `acf-json/group_glover_event_fields.json` — Event fields and conditional admin workflow.
- `acf-json/group_glover_location_fields.json` — Location address fields.
- `acf-json/group_glover_organizer_fields.json` — Organizer calendar-color metadata.
- `includes/features/event-calendar/event-calendar.php` — feature coordinator loaded by `functions.php`.
- `includes/features/event-calendar/event-calendar-admin.php` — Event-only loading for the schedule-editor assets.
- `includes/features/event-calendar/event-calendar-admin.css` — Google Calendar-inspired schedule and custom-recurrence presentation.
- `includes/features/event-calendar/event-calendar-admin.js` — date-aware Repeat labels, weekday presentation, section placement, and live Schedule summary.
- `includes/features/event-calendar/event-calendar-validation.php` — ACF cross-field schedule validation.
- `includes/features/event-calendar/event-calendar-routing.php` — landing-page-aware Event URLs and redirects.
- `docs/features/event-calendar.md` — this source of implementation context.

No public Event Calendar CSS, JavaScript, components, archive template, or single template exists in the current stage. The current CSS and JavaScript run only on Event editing screens in wp-admin.

## Dependencies

- WordPress.
- ACF Pro, installed locally and intentionally excluded from the public repository.
- ACF Local JSON loading from the theme's `acf-json` directory.

No new dependency is required.

## Backend usability and accessibility considerations

The WordPress Event editor is an authenticated, staff-only backend tool. It is not included in the public website's ADA Title II or WCAG conformance scope or claims. The following safeguards preserve practical usability and native ACF behavior; employee accessibility or reasonable-accommodation needs must be addressed separately if they arise.

- Schedule sections and conditional fields must retain meaningful visible labels, instructions, programmatic names, logical source order, and predictable focus order (WCAG 2.2 SC 1.3.1, 1.3.2, 2.4.3, and 3.3.2).
- Schedule section headings are static text, not buttons, and must not expose irrelevant expanded/collapsed states.
- All day, weekday, monthly-rule, and series-ending controls use native checkbox, radio, and select inputs. Their custom presentation must retain 44-by-44-pixel targets, full programmatic names, checked states that are not communicated by color alone, and visible keyboard focus (SC 1.4.1, 2.4.7, 2.4.11, 2.5.8, and 4.1.2).
- The generated Schedule summary uses a labelled `output` with `aria-live="polite"` and `aria-atomic="true"`. Updates are briefly debounced so typing does not produce excessive announcements (SC 4.1.3).
- At narrower widths, controls return to a single-column layout with visible field labels so the form retains meaningful order and reflows without horizontal scrolling (SC 1.3.2 and 1.4.10).
- Validation errors must identify the field and explain how to correct it (SC 3.3.1 and 3.3.3).
- Future rendering must identify organizers and statuses in text rather than by color alone (SC 1.4.1).
- Future document links must have meaningful names and expose useful file-format information.
- Uploaded PDF, DOC, and DOCX accessibility is not verified by WordPress or ACF.
- The Organizer color picker is unrestricted, so a future renderer must not assume its color has sufficient contrast.

Dedicated backend accessibility testing was not requested and has not been performed. This editor is not part of the public site's formal ADA/WCAG verification scope.

## Smoke-test checklist

1. In ACF, synchronize the Event post type, Organizer taxonomy, Organizer Details, and Event Details definitions when Local JSON changes are reported.
2. Confirm Event Type remains the required four-choice select.
3. Confirm each Event requires exactly one Organizer and that editors cannot add Organizer terms from the Event form.
4. Confirm Organizer terms are flat and do not have public taxonomy URLs.
5. Confirm Schedule is the only schedule-related top-level tab; its section headings are static text with no arrows or collapse behavior; dates, times, All day, and Repeat share the first section; and conditional sections appear in the documented order.
6. Exercise every Repeat choice and confirm only relevant fields appear, date-aware Repeat labels update from Start Date, and the live summary accurately describes the current values.
7. For Custom, test each unit; verify circular weekly-day controls, monthly basis, all three ending choices, and the matching summary sentence.
8. Enter a custom End date before Start Date and confirm saving is blocked with a field-level correction message.
9. For Selected dates, confirm at least one additional date is required and that dates on/before Start Date or duplicate dates are rejected.
10. Add cancelled, rescheduled, moved, and note-only changed dates; confirm required conditional fields appear.
11. Create a repeating meeting and confirm Meeting records by date appears on Meeting Details while top-level public notice/documents do not.
12. Create a non-repeating meeting and confirm top-level public notice, agenda, minutes, and supporting documents appear.
13. Change each document source and confirm only the selected, required field appears.
14. Confirm the Event archive remains `/calendar/` after permalinks are refreshed.
15. Confirm Event landing-page routing remains unchanged.

The calendar archive has no dedicated template yet, so it may use the theme's generic `index.php` during this smoke test. That is expected in this stage.

## Verification approach

- Parse every ACF JSON file and check field keys for duplicates.
- Verify every conditional-logic field reference resolves to an existing field key.
- Run PHP syntax checks on `functions.php` and every `event-calendar` include.
- Run a JavaScript syntax check on the Event admin behavior and the existing frontend production build.
- Perform the smoke-test checklist against the local WordPress admin when the database is available.
- Visually review the exact local Add Event screen in Arc at desktop and narrow widths, including each custom recurrence ending state and incomplete summary states.

## Known limitations and risks

- Public occurrence generation is not implemented, so preset and custom schedules are data contracts only at this stage.
- The generated admin summary describes the editor's data but does not calculate or preview every future occurrence; the future occurrence engine must be tested against the same schedule contract.
- ACF Local JSON requires an explicit sync when database definitions differ from these files.
- The Events structure is clean-slate and contains no content to migrate; superseded taxonomy and recurrence fields were intentionally removed without aliases, fallbacks, or migration code.
- Occurrence exception and meeting-record dates are not yet checked against generated dates because occurrence generation is not implemented.
- The current routing target includes `event_id`, but the future archive must use it to focus or identify the requested event or occurrence.
- The prior production URL `/calendar-of-events/` does not yet redirect to `/calendar/`.
- A single change row cannot currently represent an occurrence that is both rescheduled and moved.

## Open questions for the next approval gate

- Whether recurring meeting records also need per-occurrence supporting packets, meeting-mode overrides, and virtual-link overrides.
- Whether an individual occurrence needs combined move-and-reschedule controls.
- How the public archive should focus an event when `event_id` identifies a repeating series with many occurrences.
- When to add the legacy `/calendar-of-events/` redirect.

## Material change history

- 2026-08-03 — Replaced Schedule accordions with static heading-and-description fields, moved recurring Meeting records by date to Meeting Details without changing its data contract, and restyled changed-date rows to match the schedule controls.
- 2026-08-03 — Added the Event-only Google Calendar-inspired Schedule presentation, placed All day and Repeat with the date/time controls, added circular native weekday toggles and date-aware preset labels, and added a live human-readable Schedule summary without changing stored field values.
- 2026-08-03 — Established the private, flat Organizer taxonomy; kept Event Type as the existing select; consolidated schedule editing onto one tab; replaced the repeating toggle and separate recurrence tabs with Google Calendar-style Repeat choices, Custom repeat settings, Selected dates, changed-date exceptions, and Meeting records by date; removed the duplicate recurrence start field and replaced its validation with chronological schedule validation.
- 2026-08-03 — Created the feature document; added occurrence-level meeting records, moved-location exceptions, explicit document sources, `/calendar/`, private taxonomy archives, and landing-page-aware Event routing.
