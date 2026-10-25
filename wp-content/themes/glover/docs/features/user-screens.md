# User Screens

The town's layout for WordPress's Profile (`profile.php`), Edit User (`user-edit.php`) and Add New User (`user-new.php`) screens, including when they open in the Document Library's Uploaders dialog. Approved from the "Glover User Screens" design canvas (https://claude.ai/artifact/6BGMLM7Z4C9To5ehFRFsfM).

## Approved Scope

- A header across the top of the screen in place of WordPress's page heading.
  - Profile and Edit User show the person's initials, name (their Nickname, else first and last name, else username), email and username, plus labels for Role, how they sign in and when they registered. The header also has a breadcrumb, Delete User (when you may delete them; a button the same size as Update User, outlined in WordPress's delete red with no fill), Update User, and links to each section. When the content area is narrower than 640px, the buttons drop below the name, each only as wide as its text, and the initials get smaller. In the Uploaders dialog the header runs the full width of the dialog.
  - Add New User shows a placeholder icon, the heading and the Add User button.
- The fields sit in open sections on the page, not in boxes. Each section has its title and a short introduction on the left and its fields on the right. Short fields sit two to a row. When the form is narrower than 900px (a tablet or phone, or the Uploaders dialog), the title and introduction stack on top of the fields. Below 520px the fields go one to a row. Both breakpoints are CSS container queries on the form's width, not the screen's.
  - **Profile:** first and last name, nickname, display name, email, username (the full width of the section) and Role. On Add New User this section also has Language.
  - **Document Submitter:** the ACF field group. It shows or hides with Role (see [Document Library](document-library.md)), and so does its link in the header.
  - **Sign-In and Security** (just **Sign-In** on Add New User): password, password reset, Send Sign-In Instructions and sessions. Add New User has the password and Send User Notification here. Actions show their description beside the button.
  - **Preferences** (Profile and Edit User only): Language, the editor options and Toolbar.
  - Any other field group or plugin section becomes a section of its own after Profile, titled by its heading.
- The Admin Color Scheme picker is removed. Everyone sees WordPress's Modern scheme (its current default), including anyone who chose another scheme before.
- Website, Keyboard Shortcuts and the About the User section (Biographical Info and Profile Picture) are hidden.
- Application Passwords is turned off for the whole site, so its section is gone from every Profile and Edit User screen (see below).
- Role menus offer only the town's roles, Administrator, Editor and Document Submitter: Add New User, Edit User, Users > Change role to, and Settings > General > New User Default Role. Subscriber, Contributor and Author still exist; they are just not offered. New accounts default to Document Submitter.

### Send Sign-In Instructions

On someone else's Edit User, administrators get **Send Sign-In Instructions**, beside Send Reset Link. It emails the person how to sign in, in the shared email layout:

- a Document Submitter with boards gets the Document Library's welcome email again (their boards, whether they are reviewed, and the sign-in steps);
- everyone else, including a Document Submitter without boards yet, gets "How to Sign In": the numbered steps for /sign-in/ and a Sign In button.

The sign-in page is unchanged: the person still asks for their own code there, because a code is only accepted right after the person asks for it on that page. The email has no set-password link, so a reset link they already have keeps working. It sends at most once a minute per person, and the row's status line says what happened ("Sign-in instructions sent to …"). Without JavaScript the button is a link that sends and comes back with a notice.

### Application Passwords

WordPress (since 5.6) lets apps and scripts, such as the WordPress phone app or an automation service, connect to a site's REST API or XML-RPC with their own password. Each application password belongs to one person's account and acts with that person's permissions, which is why WordPress lists them on every user screen. They can be revoked one at a time and can't be used to sign in on the normal sign-in page.

Nothing at the town uses them: people sign in with Google or an emailed code, the Google Drive backup uses its own Google account, and the public document search needs no sign-in. So the feature is off (`wp_is_application_passwords_available` returns false), which removes the section and makes WordPress refuse application-password requests. On a site without HTTPS, as locally, WordPress would still show the section to say the feature needs HTTPS, so a style hides it while the feature is off. To turn it back on, remove the `wp_is_application_passwords_available` line in `user-screens.php`.

## Non-Goals

- No new fields or settings. Every field is WordPress's or ACF's own, moved into the layout, and saves exactly as before.
- No front-end styles. The screens use WordPress admin styling and its admin theme color.
- Saved values are not deleted. A hidden Biographical Info still posts its saved text, so it is kept.
- The hidden roles are not deleted or converted. Anyone who already has one keeps it.

## Files

| File | Role |
|---|---|
| `includes/features/user-screens/user-screens.php` | Coordinator, required from `functions.php`. Removes the color picker and forces Modern, turns off Application Passwords, filters the role menus and default role, enqueues the assets with each screen's section list, and prints the header as a `<template>`. |
| `includes/features/user-screens/user-screens-sign-in.php` | Send Sign-In Instructions: the Edit User row (`edit_user_profile`), the admin-ajax and no-JavaScript handlers, the notice, and the button's script. Uses `glover_document_library_send_sign_in_instructions()` and the mu-plugin's `glover_account_email_send_sign_in()`. |
| `includes/features/user-screens/assets/user-screens.js` | Builds the layout from WordPress's own rows. |
| `includes/features/user-screens/assets/user-screens.css` | The header, sections and fields. |

## How It Works

- WordPress adds `admin_color_scheme_picker` after the theme loads, so it is removed on `admin_init`. A `get_user_option_admin_color` filter returns `modern` (not `fresh`, the older Default scheme), so an earlier choice no longer applies.
- Every role menu is built from `get_editable_roles()`, so an `editable_roles` filter removes `subscriber`, `contributor` and `author` (`GLOVER_USER_SCREENS_HIDDEN_ROLES`). When editing someone who still has one, theirs stays in their own menu (read from `user_id`), because otherwise nothing would be selected and saving would quietly switch them to the first role listed. WordPress also refuses to save a role that isn't in this list. An `option_default_role` filter returns `document_submitter` while the saved New User Default Role is a hidden one; the option itself is unchanged. Settings > General offers only Document Submitter there, because WordPress never offers Administrator or Editor as the default.
- WordPress has no hooks for regrouping these screens, so `user-screens.js` does it in the browser.
  - It finds each table row by its `user-<name>-wrap` class, or by its control's id on Add New User, whose rows have no classes.
  - It turns each row into a field in the section `glover_user_screens_sections()` lists for it. Rows are moved, never copied, and keep their classes and ids, so WordPress's password and application password scripts and ACF keep working.
  - Rows the town doesn't use go into a hidden holder inside the form, so their saved values still post.
  - ACF field groups keep their own table, restyled as a column.
- The script runs as soon as it loads in the footer, before `DOMContentLoaded`, so `submitter-fields.js` and WordPress's scripts find the finished layout.
- The header comes from `user-screens.php`, so its text is escaped on the server. The save button moves into the header and keeps its tie to the form through the `form` attribute.
- WordPress's notices land under the header, because `.wp-header-end` moves there too.
- The section link for the part of the page in view is marked with `aria-current`.
- How someone signs in is worked out from the Glover Google must-use plugin. A town Google account shows Google when Google sign-in is set up, and anyone else shows Emailed Code. Without the plugin, the label and the sign-in notes are left out.
- Until the script runs, the form is hidden so WordPress's layout doesn't flash. If the script never runs, it shows anyway after two seconds in WordPress's own layout. Without JavaScript the screens keep WordPress's layout, and the hidden rows are hidden with CSS instead.

## Accessibility

wp-admin only, so outside the public-site WCAG scope in [`../requirements/accessibility.md`](../requirements/accessibility.md). Even so:

- Each section is a `section` labelled by its `h2`.
- The header has the page's only `h1`.
- The breadcrumb and section links are labelled `nav`s.
- The initials circle is hidden from screen readers.
- Status is written as text, never shown by color alone.
- `display: none` also removes hidden rows from the accessibility tree.

## Smoke Test

1. Open another person's Edit User. Confirm the header (name, email, username, Role, Signs In With, Registered, Delete User, Update User) and the four sections, with no Admin Color Scheme, Keyboard Shortcuts or About section.
2. Change Role to Editor. Confirm Document Submitter and its header link disappear and the Role label changes. Change it back.
3. Choose Update User. Confirm "User updated." shows under the header and the values are kept, including any existing Biographical Info.
4. Choose Set New Password. Confirm the password field opens and the weak-password confirmation appears only for a weak password.
5. Open your own Profile and confirm the same layout, with Update Profile and no Delete User.
6. Open Add New User. Confirm Profile and Sign-In, and that choosing Document Submitter shows that section. Add a user and confirm it saves.
7. Confirm there is no Application Passwords section on Profile or Edit User.
8. On a Document Submitter's Edit User, choose Send Sign-In Instructions. Confirm the status line names their email and the welcome email arrives; choose it again at once and confirm the wait message. Repeat for an Editor and confirm the How to Sign In email.
9. On Add New User, Users > Change role to, and Settings > General, confirm the role menus offer only Administrator, Editor and Document Submitter (Settings > General: Document Submitter). Open Edit User for someone with the Subscriber role, save without touching Role, and confirm they are still a Subscriber.
10. Open Add User and Edit User from Document Library > Settings > Uploaders. Confirm the layout fills the dialog with no breadcrumb, stacks at narrow widths, and that saving still closes the dialog.

## Known Limitations

- The layout depends on WordPress's row classes and control ids. A WordPress update that renames them leaves those rows in an unnamed section until `glover_user_screens_sections()` is updated.
- Hiding the About section without JavaScript relies on CSS `:has()`, supported in current Chrome, Edge, Safari and Firefox.

## Material Change History

- 2026-10-25: Website is hidden on Add New User, Profile and Edit User (moved to the hidden holder, so a saved value still posts; `.user-url-wrap` is hidden by CSS without JavaScript), and Username takes the full width of the Profile section. Not checked in the browser: the browser pane was signed out.
- 2026-10-23: The header name is the Nickname, else first and last name, else username. Delete User is now a red-outlined button the same size as Update User. The header stacks below 640px, measured on the content area (a container on `#wpbody-content`, because a container on the header can't run under the floated Help tab), and sections stack below 900px of form width. The Uploaders dialog's styles now also clear WordPress's folded-menu margin and phone-width toolbar padding, so the header spans the dialog. Checked in the browser on Edit User at full width, 960px, 900px and 560px in the dialog, and 375px.
- 2026-10-23: Added Send Sign-In Instructions to Edit User (`user-screens-sign-in.php`). Checked with mail intercepted (the welcome email for a submitter with boards, How to Sign In for a submitter without boards and for an editor, the once-a-minute wait, and that a Document Submitter can't send), the admin-ajax request over HTTP (nonce and permission checks, stopping at the wait), and its place beside Send Reset Link in headless Chrome.
- 2026-10-23: Turned off Application Passwords (nothing uses them) and explained the feature here. Restored the Modern color scheme and the role menu limits, which the layout rewrite had dropped. Checked as an administrator: no Application Passwords section on Edit User (headless Chrome) or Profile, its REST route refused (501), the Modern stylesheet loaded, and the role menus on Edit User and Add New User.
- 2026-10-23: New layout for Profile, Edit User and Add New User, from the approved design canvas: a header with the person's details and the save button, and open sections (Profile, Document Submitter, Sign-In and Security, Preferences) built from WordPress's own rows by `assets/user-screens.js`. "No restyling" is no longer a non-goal. `submitter-fields.js` now hides the whole section and its header link. Checked in the browser as an administrator on Edit User (including a save and switching Role), Profile, Add New User, and Add New User in dialog mode at 760px. Adding a user and the dialog's close-and-reload were not checked.
- 2026-10-23: Added. Checked by rendering Profile and Edit User (also in the Uploaders dialog) as an administrator: no color scheme row and the hiding styles present. Not checked visually in a browser.
