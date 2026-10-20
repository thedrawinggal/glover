# Event Calendar

## Status

Admin calendar stage. Community Events and Meetings are separate public post types that share a compatible schedule contract. Location is a reusable admin-only post type, and meeting records live only on Meetings. A shared occurrence engine expands schedules into dated occurrences, and the Events admin screen has List and Calendar tabs. The public calendar does not exist yet.

Last updated: 2026-10-20.

## User need and purpose

Residents looking for community events need a calendar of community events only. Residents looking for government meetings use a separate Meetings calendar, so meetings never appear on the community calendar and the community calendar has no event types. Town staff need purpose-specific editing flows: Meetings own public notice status and meeting mode, while Events own optional landing-page details. Agendas, minutes, and other meeting documents live in the [Document Library](document-library.md), not on Meeting records.

The Phase 1 content source recommends `/calendar/` as the public destination. The previous site used `/calendar-of-events/` and an embedded Google calendar with little explanatory content.

## Approved current scope

- ACF Local JSON for the `community-events` (Event), `meeting`, and `location` post types.
- A distinct Meeting editor with meeting mode, virtual link, and public notice status.
- One Schedule tab organized with static ACF heading-and-description fields without adding data nesting or collapsible controls.
- Two explicit schedule methods: one or more specific date/time rows, or a structured repeating schedule.
- Specific occurrences use a compact native ACF repeater; repeating schedules reveal interval, unit, weekdays, monthly rule, and series-ending controls.
- Shared Event-and-Meeting admin behavior with native ACF field presentation.
- A live, plain-language Schedule summary that describes dates, times, recurrence frequency, selected weekdays or monthly rule, and series ending without saving duplicate schedule data. Only this summary bubble receives feature-specific styling.
- Series-level changes for cancelled, rescheduled, moved, and noted occurrences.
- Occurrence-level public notice status for recurring meetings.
- Placeholder Meeting fields for a future Local Minutes source key and recognized name aliases; no feed ingestion is implemented.
- Validation for chronological recurrence end dates.
- `/events/` as the Event archive slug. This departs from the Phase 1 recommendation of `/calendar/`; see Open questions.
- A shared PHP occurrence engine that expands specific dates and repeating schedules, with their changed dates, into dated occurrences for a bounded range.
- List and Calendar tabs on the Events admin screen. List is the native WordPress list table. Calendar is a month view, built on FullCalendar, showing community events only.
- Each calendar occurrence links to its Event editor; every date of a repeating series opens the same post.
- Each calendar day has an Add button, revealed on hover or keyboard focus and always visible on touch screens, that opens a new Event with that date prefilled.
- Non-landing-page Event links and direct requests resolve to the calendar archive.
- Alerts, closures, and emergency notices are not modeled in Events or Meetings; an Alerts content model is intentionally deferred.

## Current non-goals

- Public month, week, or agenda/list views.
- Admin week, day, or agenda views.
- Dragging, resizing, or otherwise rescheduling events from the calendar. Dates change only in the editor.
- Meetings on the community calendar. Meetings get their own separate calendar later.
- Event types or categories.
- Event landing-page template.
- Alert post type or alert field group.
- iCal feeds, homepage widgets, print schedules, reminders, notifications, permissions, duplication, or final styling.

## Source evidence

- `project-files/townofglover-content-archive/PHASE-1-CONTENT-AUDIT.md`, Page 12 — Calendar.
- `project-files/townofglover-content-archive/pages/calendar-of-events/index.md`.
- Original event-calendar and municipal workflow requirements supplied by the repository owner on 2026-08-03.
- Google Calendar event editor screenshot supplied by the repository owner on 2026-08-03 as an early editing-model reference; the current implementation intentionally uses native ACF styling.

## Data and field contracts

### Event records

- Post type: `community-events` (admin label "Community Event Calendar").
- Public archive: `/events/`.
- Admin labels use the singular "Event" (Add New Event, Edit Event, All Events) while the menu reads "Community Event Calendar".
- Common required fields include `event_location`, `short_description`, and `schedule_type`. The active schedule method supplies its own required dates and times.
- Events have no Event Type. The `event_type` taxonomy and field were removed on 2026-10-20.
- The optional Event `contact` group supports a public person or organization, phone number, email address, and website URL.
- `has_landing_page` determines whether an Event owns a standalone public page. When false, generated and manually entered Event URLs resolve to `/events/?event_id=<post-id>`.
- Event landing-page fields are ordered as Long Description, Registration / RSVP Link, Cost / Fee, conditional Cost Details, and Gallery. Related Documents and Related Event Series are not part of the Event field group.
- `cost_or_fee` is required for landing-page Events and stores `free_entry` or `paid_ticket`. Paid Ticket requires the single-line `cost_details` field, limited to 180 characters and one sentence.

### Meeting records

- Post type: `meeting`.
- Public archive: `/meetings/`.
- Shared fields use the same meta names as Events where applicable, including `event_location`, `short_description`, `schedule_type`, `specific_occurrences`, and the structured recurrence fields.
- Meeting fields use their own ACF field keys so Event and Meeting field definitions remain independent while front-end queries can normalize common meta names.
- Meeting-only fields include `meeting_mode`, `virtual_meeting_link`, `warning_status`, `occurrence_meeting_records`, `local_minutes_source_key`, and `local_minutes_name_aliases`.
- Meetings hold no document uploads or document links. The former `agenda`, `minutes`, and `supporting_documents` fields were removed on 2026-10-19; the Document Library is the only home for meeting documents.

### Schedule editing structure

Schedule fields remain on the Schedule tab in this order:

1. Required `schedule_type`: “On specific date(s)” or “On a repeating schedule.”
2. A compact `specific_occurrences` repeater, shown for specific dates, with Date, Status, Time TBD, Start Time, and End Time in each row.
3. Start Date, required start and end times, and recurrence controls, shown for a repeating schedule.
4. A generated Schedule summary, placed after the controls that define the active schedule.
5. Changes to individual dates, shown for every repeating schedule.

The section descriptions are native ACF message fields whose labels are promoted to semantic `h3` headings by the admin JavaScript. This changes markup only and adds no field styling, group prefixes, or saved-data nesting. Public notice by date (`occurrence_meeting_records`) is located on Meeting Details and shown only for repeating Meetings. The summary is an admin-only live output derived from the current native ACF values; it is not saved as another field.

### Dates and repeat patterns

- `schedule_type` is required and stores `specific_dates` or `recurring`.
- `specific_dates` uses `specific_occurrences`; every row owns one required `date`, required `status` (`scheduled` or `cancelled`), optional `time_tbd`, and required `start_time` and `end_time` values when the time is known.
- Editors retain canceled specific dates by changing their Status instead of deleting the row; the live summary labels those occurrences as canceled.
- `recurring` uses the top-level `start_date`, `start_time`, and `end_time`, all required. It does not offer Time TBD or a top-level occurrence End Date.
- In specific-date rows, Time TBD hides and removes the requirement for the corresponding start and end times until both are known.
- For both scheduling methods and rescheduled-date overrides, End Time may equal Start Time but cannot be earlier; the editor applies native live validity and PHP repeats the check before saving.
- Repeating schedules use `recurrence_interval` plus `recurrence_unit` (`day`, `week`, `month`, or `year`). The controls read as a sentence such as “Repeat every 2 weeks.”
- When an editor first chooses a weekly repeating schedule, the Start Date's weekday is selected automatically if no weekdays have been chosen; editors may then add or remove days.
- Weekly schedules require one or more `recurrence_days` values.
- Monthly schedules require `recurrence_monthly_basis`: `day_of_month` or `weekday_position`, both derived from the Start Date.
- Recurrence endings use `recurrence_end_type`: `never` (shown to editors as “No end date”), `on_date`, or `after_count`. `on_date` requires `recurrence_end_date`; `after_count` requires `recurrence_end_count` and counts the Start Date as the first occurrence.
- Future occurrence generation must always use a bounded requested range so a never-ending series cannot create an infinite loop.

### Changes to individual dates

- Field: `occurrence_exceptions`.
- Each row targets one scheduled `date` without changing the base repeat rule.
- Supported statuses remain `cancelled`, `rescheduled`, `moved`, and `note`.
- A rescheduled row requires `new_date` and may override start/end time.
- A moved row requires `new_location` and may include a public note.
- One row currently represents one change type. Combining a move and reschedule for the same occurrence is not supported in this stage.

### Recurring meeting records

- Field: `occurrence_meeting_records`, labeled "Public notice by date" in the editor.
- Available only on the Meeting post type and visible when `schedule_type` is `recurring`.
- Each row attaches `warning_status` to one scheduled `date`.
- These rows do not create calendar occurrences. The repeat schedule remains the source of dates, and the future occurrence engine must merge the matching row into the generated occurrence.
- Top-level `warning_status` is limited to specific-date meetings to avoid competing sources.
- Meeting mode, virtual meeting link, location, contact, and short description remain series-level defaults.

### Local Minutes placeholder

- `local_minutes_source_key` reserves a future stable association to a Local Minutes town-body, source-folder, or feed-category identifier.
- `local_minutes_name_aliases` stores optional fallback names, one per line, for future reviewed matching.
- These fields do not fetch, match, cache, or publish Local Minutes documents. The feed contract remains an external dependency and title-only automatic matching is intentionally not implemented.

### Meeting documents

- Meetings no longer store agendas, minutes, or supporting documents, as files or as links.
- Those documents are submitted, approved, and published through the [Document Library](document-library.md), tagged with one or more boards.
- Connecting a Meeting, or one of its dates, to its library documents is deferred to a later, unscheduled phase.

### Occurrences

- `glover_event_calendar_get_occurrences_in_range( $start, $end, $post_status )` returns every community-event occurrence between two inclusive `Y-m-d` dates, sorted by date and start time. The admin calendar uses it now; the public calendar must use the same function.
- Each occurrence has `post_id`, `date`, `start_time`, `end_time`, `time_tbd`, `status` (`scheduled`, `cancelled`, or `rescheduled`), `original_date`, `location_id`, and `note`.
- Specific-date rows become occurrences directly; a Canceled row stays as a `cancelled` occurrence.
- Repeating schedules count the Start Date as the first occurrence, begin weekly periods on the site's Week Starts On setting, skip months that lack the numbered day (such as the 31st), and repeat a Start Date in its month's last week on the last matching weekday. These rules mirror the editor's Schedule summary.
- Changed dates apply to the matching generated date: Cancelled marks it `cancelled`; Rescheduled moves it to `new_date` with optional new times (a new start without a new end keeps the series' duration); Moved replaces its location; every change type carries its public note. A change row that matches no generated date is ignored.
- Generation is always bounded by the requested range, a series end date, or an occurrence count, with a hard ceiling of 20,000 schedule periods.
- Occurrences are computed on request and never saved, so the schedule fields remain the only source of schedule truth.

### Admin calendar

- The Calendar tab is `edit.php?post_type=community-events&view=calendar`, rendered in place of the list table. A `month=YYYY-MM` argument keeps the visible month across reloads and returns from the editor.
- It reads `GET /wp-json/glover/v1/admin-calendar?start=&end=`, limited to users who can edit Events and to roughly one month per request. It includes published, scheduled, draft, pending, and private Events; non-published ones are labeled Draft.
- Canceled occurrences are struck through and labeled Canceled; rescheduled ones are labeled Rescheduled; Time TBD occurrences are shown as all-day items labeled Time TBD.
- The Add button opens `post-new.php?post_type=community-events&glover_event_date=YYYY-MM-DD`. A new Event then starts with one Specific dates row on that day, and the same day as its Start Date if the editor switches to a repeating schedule. Nothing is saved until the editor publishes.

### Locations

- The Event Type and Organizer taxonomies have been removed.
- Post type: `location`; it is admin-only and stores reusable address details. A virtual location may intentionally have no street address.

## File map

- `acf-json/post_type_event.json` — Event registration and `/events/` archive.
- `acf-json/post_type_meeting.json` — Meeting registration and `/meetings/` archive.
- `acf-json/post_type_location.json` — reusable, non-public Location registration.
- `acf-json/group_glover_event_fields.json` — Event overview, schedule, and optional landing-page fields.
- `acf-json/group_glover_meeting_fields.json` — Meeting overview, schedule, meeting-record fields, and Local Minutes integration placeholders.
- `acf-json/group_glover_location_fields.json` — Location address fields.
- `includes/features/event-calendar/event-calendar.php` — feature coordinator loaded by `functions.php`.
- `includes/features/event-calendar/event-calendar-occurrences.php` — shared occurrence engine.
- `includes/features/event-calendar/event-calendar-admin-calendar.php` — List / Calendar tabs, calendar screen, admin calendar REST feed, and new-Event date prefill.
- `includes/features/event-calendar/event-calendar-admin-calendar.js` — FullCalendar month view, day Add buttons, and occurrence labels.
- `includes/features/event-calendar/event-calendar-admin-calendar.css` — Add button and occurrence-state styling for the admin calendar only.
- `includes/features/event-calendar/vendor/fullcalendar/` — FullCalendar 6.1.20 standard bundle and its MIT license.
- `includes/features/event-calendar/event-calendar-admin.php` — Event-and-Meeting loading for the schedule-editor behavior and summary style.
- `includes/features/event-calendar/event-calendar-admin.css` — styling scoped only to the generated Schedule summary bubble.
- `includes/features/event-calendar/event-calendar-admin.js` — semantic message headings, schedule-method behavior, date-aware recurrence defaults, and live Schedule summary.
- `includes/features/event-calendar/event-calendar-validation.php` — ACF cross-field schedule validation.
- `includes/features/event-calendar/event-calendar-routing.php` — landing-page-aware Event URLs and redirects.
- `docs/features/event-calendar.md` — this source of implementation context.

No public calendar CSS, JavaScript, components, archive template, or single template exists in the current stage. Editor CSS is limited to the Schedule summary bubble and the editor JavaScript runs only on Event and Meeting editing screens; the calendar CSS and JavaScript load only on the Events Calendar tab.

## Dependencies

- WordPress.
- ACF Pro, installed locally and intentionally excluded from the public repository.
- ACF Local JSON loading from the theme's `acf-json` directory.

- FullCalendar 6.1.20 (MIT), vendored in the theme and loaded only on the Events Calendar tab. No CDN is used at runtime.

## Backend usability and accessibility considerations

The WordPress Event and Meeting editors are authenticated, staff-only backend tools. They are not included in the public website's ADA Title II or WCAG conformance scope or claims. The following safeguards preserve practical usability and native ACF behavior; employee accessibility or reasonable-accommodation needs must be addressed separately if they arise.

- Schedule sections and conditional fields must retain meaningful visible labels, instructions, programmatic names, logical source order, and predictable focus order (WCAG 2.2 SC 1.3.1, 1.3.2, 2.4.3, and 3.3.2).
- Schedule message labels are promoted to semantic headings without custom field styling.
- Specific-date Time TBD, weekday, monthly-rule, and series-ending controls use native ACF checkbox, radio, and select presentation with their programmatic names and states intact (SC 1.4.1 and 4.1.2).
- Event and Meeting screens explicitly enqueue ACF's bundled time-picker addon stylesheet and use ACF's `time_picker_args` filter to limit its native picker to hour and minute precision; no theme-authored time-picker rules are added.
- The generated Schedule summary uses a labelled `output` with `aria-live="polite"` and `aria-atomic="true"`. Updates are briefly debounced so typing does not produce excessive announcements (SC 4.1.3).
- Native ACF layout behavior remains responsible for field reflow at narrower widths (SC 1.3.2 and 1.4.10).
- Validation errors must identify the field and explain how to correct it (SC 3.3.1 and 3.3.3).
- Future document links must have meaningful names and expose useful file-format information.
- Uploaded PDF, DOC, and DOCX accessibility is not verified by WordPress or ACF.

- The calendar's events are links, and each day's Add button is a link with an accessible name such as "Add event on October 21, 2026". The Add button is revealed on keyboard focus, never left as an invisible tab stop, and stays visible on touch screens (SC 2.4.4, 2.4.7, and 4.1.2).
- Canceled and draft states are conveyed in text, not by strikethrough or opacity alone (SC 1.4.1).

Dedicated backend accessibility testing was not requested and has not been performed. This editor is not part of the public site's formal ADA/WCAG verification scope.

## Smoke-test checklist

1. In ACF, synchronize the Event and Meeting post types, Event Details, and Meeting Details definitions when Local JSON changes are reported.
2. Confirm the Event editor has no Event Type field and no Event Types menu remains after synchronization.
3. Confirm neither Event nor Meeting contains an Organizer field and no Organizer taxonomy menu remains after synchronization.
4. Confirm only the Schedule summary bubble receives feature-specific styling; all ACF fields retain their native presentation.
5. Confirm Schedule begins with the required scheduling-method choices and only the selected method's fields appear.
6. Under Specific dates, add one and several occurrence rows; test Scheduled, Canceled, confirmed-time, and Time TBD rows and confirm the live summary retains and labels every completed occurrence.
7. Under Repeating schedule, test each recurrence unit; verify the weekday controls, monthly basis, all three ending choices, changed-date fields, and matching summary sentence.
8. Enter a recurrence End date before Start Date and confirm saving is blocked with a field-level correction message.
9. In repeating schedules, specific-date rows, and rescheduled-date overrides, enter an End Time before Start Time and confirm live validity and save-time validation both reject it; confirm equal times are accepted.
10. Add cancelled, rescheduled, moved, and note-only changed dates; confirm required conditional fields appear.
11. Create a repeating Meeting and confirm Public notice by date appears on Meeting Details with only Date and Public Notice Status per row, while the top-level public notice does not.
12. Create a specific-date Meeting and confirm the top-level public notice appears, and that no Agenda, Minutes, or Packet / Supporting Documents fields appear anywhere on the Meeting.
13. Confirm the Local Minutes placeholder fields appear on Meetings and do not claim to import or publish feed documents.
14. Confirm the Public notice by date description points editors to the Document Library for agendas and minutes.
15. On an Event landing page, confirm RSVP Link and Cost / Fee appear above Gallery; Paid Ticket reveals the required one-sentence Cost Details field, and no Related Documents or Related Event Series fields appear.
16. Confirm the Event archive remains `/events/` after permalinks are refreshed.
17. Confirm Event landing-page routing remains unchanged.
18. Confirm the Meeting archive is `/meetings/` after permalinks are refreshed.
19. On Events, confirm List and Calendar tabs appear below the heading, List shows the native list table, and Calendar shows the current month.
20. On Calendar, confirm specific-date and repeating Events appear on the right days, including canceled (struck through and labeled), rescheduled, Time TBD, and draft occurrences, and that no Meetings appear.
21. Click an occurrence and confirm it opens that Event's editor; return and confirm the same month is shown.
22. Hover a day, and separately Tab to it, and confirm the Add button appears; click it and confirm the new Event starts with one Specific dates row on that day, and the same Start Date if switched to repeating.
23. Confirm events cannot be dragged or resized, and that Previous, Next, and Today change months.

The calendar archive has no dedicated template yet, so it may use the theme's generic `index.php` during this smoke test. That is expected in this stage.

## Verification approach

- Parse every ACF JSON file and check field keys for duplicates.
- Verify every conditional-logic field reference resolves to an existing field key.
- Run PHP syntax checks on `functions.php` and every `event-calendar` include.
- Run a JavaScript syntax check on the shared admin behavior and the existing frontend production build.
- Perform the smoke-test checklist against the local WordPress admin when the database is available.
- Visually review the exact local Add Event and Add Meeting screens at desktop and narrow widths, including each custom recurrence ending state and incomplete summary states.

## Known limitations and risks

- The occurrence engine is used only by the admin calendar until the public calendar is built.
- The editor's Schedule summary is written in JavaScript and the occurrence engine in PHP; they follow the same rules but are separate code, so a rule change must be made in both.
- Changing the site's Week Starts On setting changes which weeks an every-2-weeks schedule falls in.
- ACF Local JSON requires an explicit sync when database definitions differ from these files.
- Event records saved with an `event_type` value keep that orphaned meta. The local database had no Event Type terms or saved values when it was removed.
- Existing Event or Meeting records using former `recurrence_pattern` or `has_repeating_schedule` values require a one-time conversion to `schedule_type`, `specific_occurrences`, or the configurable recurrence fields; the field-definition change does not migrate saved schedule metadata.
- Existing Event posts previously saved with `event_type=meeting` require an explicit content migration to the Meeting post type. Existing `event_type=closure` records also require a separate editorial decision while Alerts are deferred; no automatic data mutation runs from the theme.
- Changed-date rows and meeting-record dates are not validated against generated dates in the editor; a row that matches no generated date is silently ignored.
- The current routing target includes `event_id`, but the future archive must use it to focus or identify the requested event or occurrence.
- The prior production URL `/calendar-of-events/` does not yet redirect to `/events/`.
- A single change row cannot currently represent an occurrence that is both rescheduled and moved.
- The local database holds one auto-draft under the retired `event` post type key. Any real Event content saved under `event` would need an explicit post type migration to `community-events`; none exists locally.
- Meeting records saved before 2026-10-19 with `agenda`, `minutes`, or `supporting_documents` values would keep that orphaned meta. The local database had none when the fields were removed, and no automatic migration runs.

## Open questions for the next approval gate

- Whether recurring meeting records also need meeting-mode overrides and virtual-link overrides.
- Whether the Local Minutes placeholder should become a source for Document Library records now that meetings hold no documents.
- The final Local Minutes feed schema, stable source identifier, document identifier, meeting-date field, and document-type vocabulary.
- Whether an individual occurrence needs combined move-and-reschedule controls.
- Whether the Event archive stays at `/events/` or returns to the Phase 1 `/calendar/` recommendation, and where `/calendar-of-events/` should redirect.
- How the public archive should focus an event when `event_id` identifies a repeating series with many occurrences.
- When to add the legacy `/calendar-of-events/` redirect.

## Material change history

- 2026-10-20: Added the shared occurrence engine and the Events admin List / Calendar tabs: a FullCalendar month view of community events only, occurrence links to the Event editor, and a per-day Add button that prefills the new Event's date. Removed the Event Type taxonomy and field because the calendar is community events only, with Meetings to get a separate government calendar. Relabeled Event admin strings to the singular "Event" and replaced the post type description.
- 2026-10-19: Reconciled the Event post type key: the Event Details field group, `event_type` taxonomy, Event post type taxonomy attachment, routing, validation, and admin asset loading now target `community-events`, and the Event archive is `/events/`. Field keys and meta names are unchanged.
- 2026-10-19: Removed the Meeting `agenda`, `minutes`, and `supporting_documents` fields and the per-date agenda and minutes groups; the Document Library is now the only home for meeting documents. Relabeled the per-date repeater "Public notice by date" without changing its `occurrence_meeting_records` name. Recorded the unreconciled `community-events` Event post type registration as a known risk.
- 2026-08-11 — Added an optional Website URL to Event contact information.
- 2026-08-11 — Removed Event Related Documents and Related Event Series; moved RSVP and Cost / Fee above Gallery; converted Cost / Fee to Free Entry/Paid Ticket with conditional one-sentence Cost Details.
- 2026-08-11 — Added per-date Scheduled/Canceled status to the specific Dates and Times repeater so canceled dates remain visible in the data and schedule summary.
- 2026-08-11 — Added live and save-time validation preventing an occurrence End Time from being earlier than its Start Time in both scheduling methods and rescheduled-date overrides.
- 2026-08-11 — Removed Time TBD from repeating schedules, made their start and end times unconditionally required, and simplified the date field to Start Date without helper text.
- 2026-08-11 — Replaced the All day option with Time TBD for Events and Meetings; both start and end times are required whenever the time is known.
- 2026-08-11 — Split scheduling into explicit Specific dates and Repeating schedule methods, added per-date date/time rows, and reserved Meeting fields for a future Local Minutes source key and reviewed name aliases.
- 2026-08-11 — Renamed the open-ended recurrence choice from “Never” to the clearer “No end date” without changing its stored `never` value.
- 2026-08-11 — Removed the top-level End Date from Events and Meetings; every occurrence now has one date, and separate dates are represented through recurrence.
- 2026-08-11 — Replaced the recurrence preset dropdown and selected-date mode with one native Repeating schedule checkbox that reveals the configurable recurrence controls.
- 2026-08-11 — Explicitly enqueued ACF's bundled time-picker addon stylesheet and configured its native picker for minute precision after the unused seconds control appeared in the schedule editor.
- 2026-08-11 — Corrected preset-repeat summaries so End Date is rendered as the series boundary instead of a multi-year occurrence range followed by “no end date.”
- 2026-08-11 — Added live ACF End Date feedback in both editors while retaining the matching PHP save-time validation.
- 2026-08-11 — Added Event-and-Meeting validation requiring a populated End Date to be strictly after Start Date, with same-day schedules represented by a blank End Date.
- 2026-08-11 — Restored the JavaScript that promotes ACF message labels to semantic headings without restoring custom field styling.
- 2026-08-11 — Removed all Event/Meeting-specific ACF wrapper classes and JavaScript presentation changes; only the generated Schedule summary bubble retains custom CSS.
- 2026-08-11 — Replaced the hard-coded Event Type select with an ACF-registered `event_type` taxonomy and required single-value taxonomy field, removed the Organizer taxonomy and fields, and restored styling only for the generated Schedule summary bubble.
- 2026-08-11 — Removed the feature-specific schedule-editor stylesheet and retained native ACF field styling while preserving Event-and-Meeting schedule logic, validation, and the generated schedule summary.
- 2026-08-11 — Split Meetings out of Events into a dedicated `meeting` post type and field group, retained compatible schedule meta names with unique ACF keys, shared the schedule editor and validation across both post types, reduced Event Type to Program and Community Event, and deferred Alerts/closures without creating an Alerts post type.
- 2026-08-03 — Replaced Schedule accordions with static heading-and-description fields, moved recurring Meeting records by date to Meeting Details without changing its data contract, and restyled changed-date rows to match the schedule controls.
- 2026-08-03 — Added the Event-only Google Calendar-inspired Schedule presentation, placed All day and Repeat with the date/time controls, added circular native weekday toggles and date-aware preset labels, and added a live human-readable Schedule summary without changing stored field values.
- 2026-08-03 — Established the private, flat Organizer taxonomy; kept Event Type as the existing select; consolidated schedule editing onto one tab; replaced the repeating toggle and separate recurrence tabs with Google Calendar-style Repeat choices, Custom repeat settings, Selected dates, changed-date exceptions, and Meeting records by date; removed the duplicate recurrence start field and replaced its validation with chronological schedule validation.
- 2026-08-03 — Created the feature document; added occurrence-level meeting records, moved-location exceptions, explicit document sources, `/calendar/`, private taxonomy archives, and landing-page-aware Event routing.
