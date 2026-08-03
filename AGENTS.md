# Town of Glover Website Project Instructions

This project redesigns the Town of Glover website and restructures its information so residents, visitors, property owners, businesses, officials, and other users can find and complete common tasks.

The downloaded current website is the primary evidence source for Phase 1. Use these rules whenever proposing navigation, determining whether a page should exist, consolidating current content, or drafting page content.

## Core principle

Navigation hierarchy does not determine page count. A navigation label may organize a menu without having a landing page. Every proposed page must earn its existence through a distinct user need and sufficient, maintainable content.

Favor fewer, stronger pages over thin, repetitive, or speculative pages.

## Project-wide build and collaboration requirements

The requirements in this section apply to every phase of the project, including design review, content preparation, implementation, testing, and handoff.

### Repository

- GitHub repository: `thedrawinggal/glover`
- Web URL: `https://github.com/thedrawinggal/glover`
- Local remote: `origin` using `https://github.com/thedrawinggal/glover.git`
- Canonical SSH URL: `git@github.com:thedrawinggal/glover.git`
- Default branch: `main`
- Visibility: public
- Local credentials, uploads, temporary output, downloaded binary archives, and commercially licensed plugin code must remain excluded according to `.gitignore`.

### Accessibility and ADA compliance

Accessibility is a required design and implementation consideration, even when dedicated accessibility testing has not been requested.

- Every feature plan must identify the applicable accessibility requirements, affected users and states, relevant WCAG success criteria when known, and accessibility-focused implementation and review criteria.
- Consider accessibility while designing and building every component, page, interaction, content change, document, media asset, and third-party integration. Do not postpone known accessibility requirements until a later testing phase.
- Use WCAG 2.1 Level AA as the minimum legal baseline for this municipal website under ADA Title II. Target WCAG 2.2 Level AA for all new and substantially revised work unless an approved project constraint requires otherwise. A project constraint never permits silently ignoring a WCAG 2.1 Level AA failure.
- Flag every known or suspected accessibility failure. Do not omit, minimize, or silently accept a failure, and do not describe a design or implementation as accessible while known failures remain.
- For each finding, record the affected design, content, component, state, or workflow; the affected users and practical impact; the applicable WCAG success criterion and conformance level when known; the severity; and a recommended remediation.
- Do not approve an inaccessible design as implementation-ready. Return it with clearly identified findings and required corrections.
- Dedicated accessibility testing is on demand. Do not run automated accessibility scans, manual keyboard or screen-reader audits, contrast audits, or formal ADA/WCAG compliance reviews unless the repository owner explicitly requests them in the current conversation.
- When dedicated accessibility testing is requested, recheck the implemented result; design review alone does not establish that the finished implementation is compliant.
- When dedicated accessibility testing is requested, use both automated checks and manual review. Automated results alone are never sufficient to claim accessibility or ADA compliance.
- Requested manual review must cover all relevant states and responsive layouts, including keyboard-only operation, visible and logical focus, headings and landmarks, semantic names and relationships, screen-reader output, text and non-text contrast, zoom and reflow, touch target size, forms and error handling, status messages, motion and animation, alternative text, captions or transcripts, and accessible downloads.
- Include hover, focus, active, disabled, loading, empty, validation, error, success, open, and closed states when those states exist. Accessibility requirements apply to the complete user journey, not only the default screen.
- Do not rely on color, position, shape, sound, or motion alone to communicate meaning or status.
- Require meaningful alternative text for informative images and empty alternative text for decorative images. Complex images, maps, charts, and diagrams require an equivalent accessible explanation when their information is not otherwise available in nearby text.
- Require captions for prerecorded video with meaningful audio, transcripts or equivalent alternatives where applicable, and controls for media or motion that starts automatically.
- Treat PDFs, forms, presentations, spreadsheets, and other downloadable public materials as part of the accessibility review. Do not assume that linking to a document makes the underlying information accessible.
- Treat third-party services and embedded tools as part of the user experience. Flag accessibility barriers even when the project cannot directly modify the third-party system, and identify an equivalent accessible path when one is supported.
- If dedicated accessibility testing was not requested, state that accessibility requirements were considered during planning and implementation but formal accessibility testing was not performed. Label the result as unverified rather than accessible, ADA compliant, or WCAG conformant.
- If dedicated testing was requested but complete verification was not possible, state exactly what was not tested and why. Label the result as unverified rather than compliant.

Reference standards:

- [ADA Title II web and mobile accessibility rule](https://www.ada.gov/resources/2024-03-08-web-rule/)
- [Web Content Accessibility Guidelines (WCAG) 2.2](https://www.w3.org/TR/WCAG22/)

### Plan and explicit approval gate

When a feature is proposed in chat, do not implement it immediately.

1. Inspect the relevant project files and existing patterns using read-only actions.
2. Present a concise written executable plan before making source, configuration, content, dependency, or architecture changes.
3. Include the intended outcome, scope, expected behavior, files or systems likely to be affected, accessibility requirements and review criteria, verification approach, important risks, and unresolved questions.
4. Wait for explicit human permission such as “approved,” “proceed,” or an equally clear instruction to execute the plan.
5. After approval, implement only the approved scope. If the scope, behavior, architecture, dependency choices, or accessibility implications materially change, stop, present a revised plan, and obtain renewed permission.

Read-only investigation, accessibility review, diagnosis, and planning may occur before approval when they do not modify project files or external systems. Approval to investigate or review is not approval to implement.

This approval gate is a critical stop condition for new feature requests, architectural changes, new dependencies, and code that would establish a new structural precedent.

### Small, controlled changes

- Always make the smallest coherent change that satisfies the approved requirement.
- Touch as little code and as few files as possible. Do not perform opportunistic refactoring, cleanup, renaming, formatting, or modernization outside the approved scope.
- Before editing, inspect nearby documentation and code and follow the existing organization, conventions, components, and patterns.
- Always use the existing technology stack. Do not introduce a new library, dependency, component system, build tool, service, or architectural pattern without explicit permission.
- Preserve unrelated user changes. Never overwrite, revert, reformat, or include unrelated work in the change.
- When requirements are unclear, make and document a conservative assumption only when it is low risk. Ask a focused question when the answer would affect behavior, accessibility, scope, architecture, risk, or approval readiness.
- Do not commit, push, publish, deploy, or write to a remote unless the repository owner explicitly requests that action in the current conversation.
- Self-review every change. Inspect the complete diff, address valid findings within the approved scope, and confirm that no unrelated files were modified.
- For non-trivial features, perform a distinct review pass after implementation. Present material findings and obtain confirmation before executing remediation that would expand or materially change the approved scope.

### Verification and handoff

- Verify changes in proportion to their risk. Perform dedicated accessibility testing only when the repository owner explicitly requests it in the current conversation.
- Before asking the user to review a local page, verify the exact local URL yourself. Confirm that the intended worktree or source revision, development services, ports, and target page are current and reachable.
- If the local environment is unavailable, stale, or serving the wrong project state, report that clearly and do not provide an unverified review link.
- Include important review caveats with any link, such as sample data, disabled integrations, incomplete accessibility verification, or unavailable production workflows.
- Final handoffs must state what changed, what was verified, all known or suspected accessibility findings and their status, whether dedicated accessibility testing was requested and performed, any remaining risks or open questions, and the recommended next approval gate.

## 1. Start with user needs

Organize content around what visitors are trying to accomplish, not around the town's internal departments or the structure of the current website.

Common user needs include:

- Paying a tax, bill, fee, or permit charge
- Finding an office, official, or contact
- Obtaining a license, record, form, or permit
- Finding meeting information or public documents
- Understanding recycling, roads, safety, and other town services
- Learning about elections and Town Meeting
- Finding events, recreation, lakes, trails, and community resources
- Following town news, notices, and projects

Use plain-language labels that reflect these needs.

## 2. Do not turn every navigation label into a page

For every top-level navigation item, explicitly classify it as one of the following:

- **Navigation category:** Organizes links but is not a page.
- **Content destination:** Links to a substantive page.
- **Direct action:** Links directly to a payment system, form, calendar, document, or external resource.
- **Sitewide function:** Search, alerts, language selection, and similar functionality.

Home is a content destination. All other top-level items must qualify independently.

Do not create a landing page merely to repeat the links already visible in its menu.

## 3. Make every page earn its place

Recommend a standalone page only when all of the following are true:

1. It addresses a distinct user question, task, or subject.
2. It contains information users need, not merely links available in the menu.
3. Its content is meaningfully different from neighboring pages.
4. The current site or approved project scope provides enough source material.
5. The town can identify an owner responsible for maintaining it.
6. Consolidating it into another page would make that page less clear or harder to use.

If these conditions are not met, consolidate the content or make the item a navigation label rather than a page.

## 4. Do not create thin pages

A phone number, email address, person's name, short description, or handful of links is not enough to justify a standalone page.

Do not create separate pages for:

- Individual officials with only contact information
- Departments represented by one person and a few contact details
- Committees with no substantive description, meetings, or records
- A single form or PDF
- A single external link
- Generic introductions that repeat the menu
- Empty future programs, directories, or visitor resources

Combine related contacts into an officials, offices, or contact directory unless a role has enough services, responsibilities, records, and public interactions to support a page.

## 5. Apply a page-sufficiency test

A proposed page should generally contain at least three meaningful content elements, such as:

- Operational instructions
- Hours, locations, eligibility rules, fees, or requirements
- Multiple related user tasks
- Forms or applications with explanatory context
- Meetings, records, policies, or documents
- Current notices or status information
- Substantive background needed to understand the service
- Related contacts with clearly explained responsibilities

A page made primarily of introductory language and links does not pass this test. This is a content-quality test, not a fixed word-count requirement.

## 6. Consolidate around related tasks

Place information together when users are likely to need it during the same visit.

Examples:

- Group dog licensing, vehicle registration, records, and other clerk transactions under Clerk Services, Records, and Licensing.
- Group assessment information, parcel research, land records, and permits under Property and Permits.
- Keep recycling rules, trash prices, special disposal, hazardous waste, and facility hours together.
- Consolidate official names and contact information into a maintained directory.
- Use one meeting structure for agendas, minutes, recordings, and meeting notices.
- Use one document-library structure for forms, policies, ordinances, plans, reports, budgets, and historical files.

Avoid splitting one user journey across multiple small pages.

## 7. Avoid overlapping pages

Before recommending a page, compare it with every neighboring destination. If two pages would answer substantially the same questions, combine them or sharpen their purposes.

Warning signs include:

- Community and About Glover containing the same overview
- Things to Do repeating parks, trails, lakes, and calendar content
- Government repeating the officials directory and committee pages
- Meetings & Documents repeating links provided by Meetings and the Document Library
- News, Notices, Alerts, and Updates becoming separate archives without distinct publishing rules

A user should not have to guess which of two similar pages contains the information they need.

## 8. Distinguish content from presentation

Do not create separate pages when the need can be handled through interface behavior.

Examples:

- Show urgent alerts in a sitewide banner, on Home, and on the affected service page.
- Link Pay Online directly to the consolidated payment destination.
- Use Things to Do as a homepage pathway to parks, trails, lakes, and events when it lacks distinct content.
- Store forms in the Document Library and link to them from related service pages.
- Show recent news on Home while keeping one complete News destination.
- Show upcoming events on Home while keeping the calendar as the complete destination.

## 9. Treat repeatable information as structured content

Treat news posts, meetings, events, documents, officials, alerts, and projects as structured content types whenever practical.

Do not create separate hand-built pages for each archive year, committee record collection, or document category when filters and metadata can organize them.

Content should be reusable across the site. For example:

- A meeting can appear on a committee page, the Meetings page, and the calendar.
- An alert can appear on Home and the affected service page.
- A document can appear in the Document Library and as a contextual link from a service page.
- A project update can appear on the project page and in News.

## 10. Use the downloaded current site as evidence

The complete downloaded site is the primary source for Phase 1 content recommendations.

For each proposed page:

- Review all relevant current pages, posts, documents, and attachments.
- Consolidate duplicated information.
- Preserve useful operational details.
- Identify conflicting, incomplete, outdated, or inaccessible information.
- Do not assume an existing page deserves to remain a standalone page.
- Do not treat navigation artifacts, duplicate archives, or attachment pages as meaningful content.
- Record the source pages or collections used.

Archive volume alone does not justify a page. Repetitive posts may indicate the need for filtering or structured content rather than additional pages.

## 11. Do not invent missing content

Do not create unsupported services, programs, directories, descriptions, policies, fees, schedules, procedures, or visitor resources.

If the current site does not provide enough information:

- Consolidate the available content elsewhere.
- Mark the proposed page as future scope.
- Record the missing information as an open question.

Suggestions for new content must be labeled as recommendations, not presented as existing town information.

## 12. Verify operational information

Treat the following as requiring confirmation before publication:

- Names and titles
- Terms and vacancies
- Phone numbers and email addresses
- Office and facility hours
- Fees and payment links
- Meeting schedules
- Eligibility requirements
- Disposal rules and prices
- Emergency instructions
- Ordinance and policy status
- Project status
- Document versions
- External links

Do not silently resolve contradictions. Record them under open questions.

## 13. Classify every current page

Give every current page one Phase 1 disposition:

- **Keep:** Remains a distinct destination.
- **Consolidate:** Its content moves into another page.
- **Structured content:** Becomes a news item, meeting, event, document, alert, official, or project record.
- **Navigation only:** The label organizes other destinations but is not a page.
- **Redirect:** Sends users to a replacement destination.
- **Retire:** Contains obsolete, duplicate, empty, or unusable material.
- **Future scope:** Potentially valuable but unsupported by sufficient approved content.

No current page should remain merely because it already exists.

## 14. Define each proposed page before writing it

Every proposed page brief must include:

- Navigation category
- Page title
- Suggested slug
- Primary user need
- Page purpose
- Existing content to consolidate
- Recommended content sections
- Primary calls to action
- Related structured content
- Source pages or collections
- Content owner
- Open questions
- Current pages to redirect or retire

Do not draft the page until its purpose and boundaries are clear.

## 15. Keep calls to action task-based

Calls to action should describe what the user can do.

Good examples include:

- Pay property taxes
- License a dog
- Search land records
- Report a road issue
- Find an upcoming meeting
- View agendas and minutes
- Download a permit application
- Check recycling hours

Avoid vague calls to action such as Learn More, Explore, Click Here, or More Information.

Do not propose a call to action unless a real destination or supported workflow exists.

## 16. Apply a final deletion test

Before approving a page, ask:

> If this page did not exist, could its useful content be placed more clearly on another page?

If the answer is yes, do not create the page.

When uncertain, favor fewer, stronger pages over a larger collection of thin or overlapping pages.
