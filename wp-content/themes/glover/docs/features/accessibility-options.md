# Accessibility Options (beyond AA)

## Status

Approved by Jenny 2026-10-20. Not built yet. This is the feature's maintained spec; update it as the build changes anything below.

**AA comes first.** The whole public site is built to WCAG 2.1 Level AA under the [accessibility requirement](../requirements/accessibility.md), with or without this feature. Accessibility options is a bonus layer on top of that, never a substitute for it, never a reason to defer AA work, and never cited as how the site conforms. It can be switched off in WordPress at any time, and the site must still meet AA when it is.

Mockups (untracked, at the repo root): `project-files/mockups/display-options/`

- `display-options-board.html`: the review board. Launcher states, the panel on desktop and phone, and every setting applied side by side.
- `display-options-interactive.html`: a sample Glover page where every control works. Settings save in that browser only.

Last updated: 2026-10-20.

## Summary and recommendation

A small round button fixed to the bottom right of every public page, showing the accessibility figure, opens a panel of display preferences:

- **Text size:** Default, Larger (125%), Largest (150%).
- **Wider text spacing:** on or off.
- **Easy-to-read font:** Atkinson Hyperlegible, on or off.
- **High contrast:** on or off.
- **Color blindness mode:** on or off.
- **Highlight links and focus:** on or off.
- **Reset all.**

Changes apply to the page live, are saved in the visitor's own browser, and are applied before the page paints on every later visit. The same panel also opens from an "Accessibility options" link in the header and footer.

The whole feature, and each setting in the panel, can be switched on and off by an administrator at **Settings > Accessibility Options** in wp-admin. See [WordPress settings](#wordpress-settings).

**Approach:**

1. Build it as a first-party theme feature under `includes/features/accessibility-options/`, following the feature include pattern.
2. Implement every mode by remapping the existing `--color-*` and font custom properties, or by one attribute on `<html>`. Nothing rewrites content.
3. Name it **"Accessibility options"**, with the round accessibility-figure launcher. Because the name invites comparison with overlay products, the accessibility statement should say plainly what the panel is and is not.
4. Give it its own settings screen in wp-admin, with a master switch and a switch per panel setting.
5. Build it **after** the header, footer and first public templates exist, so the modes are tested against real pages rather than a moving target.

Everything is free. The only new asset is the Atkinson Hyperlegible font (SIL Open Font License), self-hosted.

## Relationship to AA

This feature changes nothing about how the rest of the site is built:

- Every template, component and color pairing is built and verified to WCAG 2.1 AA in its default state, as the accessibility requirement sets out. That verification is done **with Accessibility options switched off.**
- Nothing that AA requires lives only in the panel. Text must still resize to 200% through the browser (SC 1.4.4), content must still survive the WCAG text-spacing values (SC 1.4.12), contrast must still pass in the default palette (SC 1.4.3, 1.4.11), and color must still never be the only cue (SC 1.4.1).
- If a page fails AA, the fix goes in that page's markup or styles, not in a panel mode.
- The panel is public content, so the panel itself must meet AA, as must every page with any combination of its settings on.

## Why this is not an overlay

The accessibility requirement rejects third-party overlays, and residents and reviewers will see a floating accessibility button and assume this is one. The difference has to be real, and stated:

- It is theme code we own and test, not a third-party script.
- It changes only presentation the visitor asks for. It never repairs markup, generates alt text, alters reading order, or changes how assistive technology reads the page. Overlay "screen reader modes" and auto-fixes are what make overlays harmful, and none of that is here.
- **The site must meet WCAG 2.1 AA with the feature switched off.** The accessibility statement page describes the panel as an extra.

What it adds over the browser and operating system, which already offer zoom, high contrast and reader views: discoverability. Many residents, older residents especially, never find those settings. One labelled button on every page is the value.

## Settings

| Setting | Values | How it works | Who it helps |
|---|---|---|---|
| Text size | 100, 125, 150% | Sets the root font size. The theme sizes all type in rem (`_typography.scss`), so everything scales; only borders are px. Visitors who need more use browser zoom or their phone's text size, which the site must support to 200% anyway (SC 1.4.4). | Low vision, older readers on phones |
| Wider text spacing | off, on | Line height 1.7 (site default 1.5), letter spacing 0.04em, word spacing 0.08em, paragraph gap 1.4em. A moderate step up, toned down on 2026-10-20 from the WCAG 1.4.12 test values, which read as too loose. The site must still survive the 1.4.12 values on its own. | Dyslexia, low vision |
| Easy-to-read font | off, on | Swaps `--font-sans` and `--font-serif` to Atkinson Hyperlegible. The browser only downloads the font when it is used, so it costs nothing for everyone else. | Low vision, some dyslexic readers |
| High contrast | off, on | Remaps the `--color-*` tokens: black text on white (no cream tint), muted grays become black, boundaries become solid. Keeps the forest green for the banner, buttons, links and statuses, since it is 12.5:1 with white and green links are 8.1:1, both past the 7:1 AAA level. The site still looks like Glover. | Low vision |
| Color blindness mode | off, on | For red-green color blindness. Remaps only the colors that carry meaning (status labels, errors, links) from green/red to navy/burnt orange, which stay distinct for protanopia and deuteranopia, and adds ✓ and ✕ symbols to statuses and a strike-through to cancelled titles. Works with high contrast on. | Red-green color vision deficiency (about 1 in 12 men, nearly all color vision deficiency) |
| Highlight links and focus | off, on | Gives every link a highlight block and thickens the focus ring to 5px. | Low vision, cognitive, keyboard users |

### Color vision, honestly

The request was for colorblindness tools, and most of what is sold under that name does not help:

- **Simulation filters** ("see the page as a colorblind person would") are a design-testing tool. They show people with typical vision what the page looks like; they do nothing for a colorblind visitor. Not included.
- **Whole-page recolor filters** (daltonization, usually an SVG color matrix over the whole page) distort photos, maps and brand colors, and the evidence that they help is thin. Not included.
- **What is included:** palette swaps for the specific colors that carry meaning, plus non-color cues. Because the site already never uses color alone to convey information (SC 1.4.1, and "Cancelled" is always a text label), this is a comfort improvement, not a fix for something broken.

Blue-yellow (tritan) color vision is rare, well under 1 in 1,000, and the palette has few blue-yellow pairs that carry meaning, so it has no mode.

The mode palettes in the mockup are first drafts. During the build each one is checked for 4.5:1 text contrast and run through a color vision simulator against real pages.

## The panel and its accessibility

- **Launcher:** a real `<button>` with the accessible name "Accessibility options", `aria-expanded`, and `aria-controls`. 56px, fixed in px so it does not grow over content at large text sizes. Forest green with a cream ring, which holds 3:1 against any page background. A visible "Accessibility options" label appears on hover and keyboard focus and can be dismissed with Escape (SC 1.4.13). When settings are on, a small count badge appears and the button's description reads "2 settings on".
- **Panel:** a non-modal panel anchored above the launcher, so the page stays visible and changes can be judged live. Below 40rem it becomes a bottom sheet and the launcher hides behind it. Its header (title and Close) stays pinned while the body scrolls.
- **Controls:** native radio buttons and checkboxes inside `fieldset`s with `legend`s. A radio group is one Tab stop with arrow keys inside it. The selected option shows a check mark as well as a fill, so state is never color alone. On/off settings are checkboxes with `role="switch"`, showing a check mark in the track when on.
- **Focus:** opening moves focus to the panel heading. Escape and Close return focus to whichever control opened it (the launcher, or the header or footer link). Clicking outside closes it without moving focus.
- **Announcements:** each change is announced through a polite live region ("Text size: Largest." or "High contrast on."), without moving focus (SC 4.1.3).
- **Reflow:** at Largest (150%) on a 375px phone, nothing scrolls sideways. Verified in the mockup.

Changing a display setting is not a change of context (SC 3.2.2), so applying it on selection, with no Apply button, is acceptable.

## WordPress settings

**Settings > Accessibility Options** in wp-admin, administrators only (`manage_options`). It follows the Document Library's pattern: an ACF options page registered in PHP, with its fields in `acf-json/group_glover_accessibility_options_settings.json`.

| Setting | Meta name (options) | Type | Default | Notes |
|---|---|---|---|---|
| Show Accessibility options | `accessibility_options_enabled` | True/false | Off | The master switch. Off means nothing from this feature reaches the page: no launcher, no panel, no header or footer link, no head script, no font. Visitors' saved choices stay in their browsers and simply stop applying; they come back if it is switched on again. |
| Text size | `accessibility_options_text_size` | True/false | On | Shows the Text size group. |
| Wider text spacing | `accessibility_options_spacing` | True/false | On | |
| Easy-to-read font | `accessibility_options_font` | True/false | On | |
| High contrast | `accessibility_options_contrast` | True/false | On | |
| Color blindness mode | `accessibility_options_color_blindness` | True/false | On | |
| Highlight links and focus | `accessibility_options_links` | True/false | On | |
| Show floating button | `accessibility_options_launcher` | True/false | On | Off keeps the panel reachable from the header and footer links only. |
| Accessibility statement page | `accessibility_options_statement_page` | Page link | None | The panel's "About accessibility on this site" link. The link is hidden while no page is selected. |

Behavior:

- The master switch starts **off**, so the feature can be built and tested locally without appearing on the public site until it is turned on.
- A setting switched off in WordPress is removed from the panel, and the head script ignores any saved value for it, so a visitor who had it on gets the default for that setting. Their saved value is kept in case it is switched back on.
- If every panel setting is switched off, the feature behaves as if the master switch were off.
- If ACF is inactive, the feature is off. There is no fallback settings store.
- The settings are database content, so they must be set again (or migrated) on the production site, as with the Document Library settings.

## Privacy and first paint

- Choices are stored in `localStorage` under one key, in the visitor's browser only. No cookie, no consent banner needed, no account, nothing sent to the town or anyone else. A private window or cleared site data simply means default settings.
- A small inline script printed early in `<head>` reads the saved choices and sets attributes on `<html>` before the stylesheet paints, so pages never flash the default look. Every read and write is wrapped so a blocked storage API cannot break the page.
- Page caching is unaffected, since nothing about the preference reaches the server.

## Proposed file map

| File | Role |
|---|---|
| `includes/features/accessibility-options/accessibility-options.php` | Coordinator, required from `functions.php`. Requires the parts below. |
| `includes/features/accessibility-options/accessibility-options-settings.php` | Registers the options page, reads the settings, and exposes one helper that says whether the feature and each setting are on. |
| `includes/features/accessibility-options/accessibility-options-render.php` | When enabled: prints the inline head script on `wp_head` (early priority) with the list of enabled settings, and renders the panel on `wp_footer`. |
| `acf-json/group_glover_accessibility_options_settings.json` | The settings fields above. |
| `components/common/accessibility-options.php` | Launcher, panel markup, and the icon symbol. Renders only the enabled settings. |
| `src/js/components/accessibility-options.js` | Panel behavior: open and close, focus return, saving, announcements. Imported in `app.js`; does nothing when the panel is not on the page. |
| `src/styles/components/_accessibility-options.scss` | Launcher and panel styles. |
| `src/styles/base/_accessibility-modes.scss` | The mode rules: token remaps under `html[data-contrast="high"]`, `html[data-color-vision="red-green"]`, and so on. |
| `src/styles/base/_colors.scss` | Gains status tokens (`--color-status-ok-*`, `--color-status-bad-*`) so modes can retune them. |
| `assets/fonts/atkinson-hyperlegible/` | Self-hosted WOFF2 files, regular, bold, italic. |
| `header.php`, `footer.php` | The "Accessibility options" link in each, printed only when the feature is on. |
| `docs/features/accessibility-options.md` | This spec. |

One ACF field group and one options page. No post types, no taxonomies, no custom database tables.

## Prerequisites found while mocking up

- **Every color must go through a token.** It nearly does already. The stock `components/common/_footer.scss` hard-codes `#000000` and `#E21B95`, and will be replaced when the real footer is built.
- **Large text on a phone finds reflow bugs.** In the mockup, at 200% on a 375px screen, a normal table, a long email address in an error message, and a flex row each caused sideways scrolling, and each needed a small fix (stacking the table, letting text break). The 200% step was dropped from the panel for this reason, but browser zoom still reaches it, so templates need the same fixes for AA reflow (SC 1.4.10). Every public template gets a pass at Largest (150%) in the panel and at 200% browser zoom.
- **The header and footer don't exist yet.** `header.php` still renders "logo", so the header link and the launcher's relationship to the real header can't be finished until they do.

## Non-goals

- Anything that changes content or how assistive technology reads the page: read-aloud, "screen reader mode", AI alt text, auto-fixes. The visitor's own device does read-aloud better.
- Simulation and whole-page recolor filters (see Color vision, above).
- Reading ruler, enlarged cursor, magnifier, saturation sliders, page "profiles" such as "ADHD friendly".
- A "stop animations" switch. The site has almost no motion and already honors the device's reduce-motion setting in `_core.scss`.
- Syncing preferences across devices or tying them to sign-in.
- A dark mode. Dropped 2026-10-20; it would double the testing of every page.
- A blue-yellow (tritan) color mode. Dropped 2026-10-20.
- The wp-admin staff editor, which the accessibility requirement already scopes out.
- Any claim that the panel makes the site conform.
- Per-page or per-role settings. The settings screen applies site-wide.

## Decisions

- 2026-10-20 - Launcher: the round button with the accessibility figure (option A).
- 2026-10-20 - Name: "Accessibility options".
- 2026-10-20 - Dark mode and the blue-yellow color mode dropped. Contrast and color vision each became a single on/off switch.
- 2026-10-20 - The 200% text size dropped; the panel tops out at 150%. Steps are labelled Default (100%), Larger (125%) and Largest (150%).
- 2026-10-20 - The color vision switch is labelled "Color blindness mode", with the description "Easier-to-tell-apart colors for red-green color blindness." "Mode" matters: a screen reader reads "Color blindness mode, switch, on", where a bare "Color blindness" label would read as if color blindness itself were being switched on.
- 2026-10-20 - High contrast keeps the forest green rather than going strictly black and white. Its description reads "Darker text and stronger outlines on a white background."
- 2026-10-20 - Wider text spacing toned down to line height 1.7, letter 0.04em, word 0.08em, paragraph gap 1.4em.
- 2026-10-20 - Approved. The site is still built and verified to AA on its own; this is a bonus layer. It gets its own settings screen in wp-admin, with a master switch and a switch per setting.
- 2026-10-20 - Code lives under `includes/features/accessibility-options/`, matching the feature's name.
- 2026-10-20 - No town sign-off needed; Jenny's approval is sufficient. When the feature is switched on, the accessibility statement page describes it as an extra.

## Open questions

None.

## Verification (when built)

- Every public template passes the accessibility requirement's AA checks with the feature switched off. This is the baseline and is checked first.
- With the master switch off: no launcher, panel, links, head script or font request appear in the page source, and nothing errors.
- Each per-setting switch removes its control from the panel, and a saved value for a switched-off setting no longer applies.
- "Show floating button" off: the panel still opens from the header and footer links.
- Every mode, alone and in combination, on each public template: no horizontal scroll at 375px with Largest (150%) text; text contrast at least 4.5:1 and UI boundaries at least 3:1 with high contrast and color blindness mode on, alone and together (TPGi Colour Contrast Analyser).
- Color blindness mode checked through a protanopia and deuteranopia simulator against real pages.
- Keyboard pass of the panel: open from all three entry points, arrow keys in groups, Escape, focus return, no trap.
- VoiceOver pass: names, group labels, switch states, and announcements.
- Private window and blocked storage: page loads with defaults and no errors.
- No flash of default styles on reload with settings saved.
- axe DevTools on a page with the panel open: zero violations.

## Material change history

- 2026-10-20 - Proposal drafted with flat HTML mockups in `project-files/mockups/display-options/`.
- 2026-10-20 - Renamed to Accessibility options; dropped Dark and Blue-yellow; toned down wider text spacing.
- 2026-10-20 - Dropped the 200% text size and relabelled the steps Default, Larger, Largest; renamed the color switch to Color blindness mode; High contrast keeps the forest green.
- 2026-10-20 - Approved. Renamed from `display-options-proposal.md` to `accessibility-options.md` as the maintained spec. Added the AA-first section and the wp-admin settings screen (master switch, per-setting switches, floating-button switch, statement page).
