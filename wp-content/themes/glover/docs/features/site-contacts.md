# Site Contacts

A screen in the Users menu, Users > Site Contacts, where an administrator chooses which WordPress user is the Webmaster and which is the Town Clerk. It sits beside All Users and Profile but is its own screen, so the roles are set in one place, not on each person's profile.

## Approved Scope

- One ACF options page, Site Contacts, under Users, for users with `manage_options`.
- Two ACF User fields, Webmaster and Town Clerk, each allowing one user or none.
- A PHP helper that returns each chosen user.

## Non-Goals

- Only the account emails read these settings so far: their footer names the Webmaster (falling back to the site admin email). The Document Library keeps its own contact settings until a switch is approved (see Open Questions).
- No fields on user profile screens, and no new roles or capabilities.
- No public display. Whether a chosen user's email may appear on the public site is undecided.

## Files

| File | Role |
|---|---|
| `acf-json/ui_options_page_glover_site_contacts.json` | The options page, menu slug `glover-site-contacts`, parent `users.php`, autoloaded. |
| `acf-json/group_glover_site_contacts.json` | The field group, shown on that options page. |
| `includes/features/site-contacts/site-contacts.php` | Coordinator and helper, required from `functions.php`. |

## Data Contract

Stored as ACF options (`post_id` `option`), so in `wp_options` as `options_<name>`.

| Label | Field key | Name | Type | Returns |
|---|---|---|---|---|
| Webmaster | `field_glover_site_contacts_webmaster` | `site_contacts_webmaster` | User, single, may be empty | user ID |
| Town Clerk | `field_glover_site_contacts_town_clerk` | `site_contacts_town_clerk` | User, single, may be empty | user ID |

## Helper

```php
glover_site_contact('webmaster');   // WP_User|null
glover_site_contact('town_clerk');  // WP_User|null
glover_site_webmaster();            // same as glover_site_contact('webmaster')
glover_site_town_clerk();           // same as glover_site_contact('town_clerk')
```

Each returns null when nobody is chosen, the chosen user has been deleted, ACF is inactive, or the role is not one of the two above. Callers must handle null.

## Accessibility

The screen is wp-admin only and uses ACF's native options page and User field (Select2) with no custom styling, so it is outside the public-site WCAG scope described in [`../requirements/accessibility.md`](../requirements/accessibility.md).

## Smoke Test

1. As an administrator, the Users menu shows Site Contacts; the screen title is Site Contacts with Webmaster and Town Clerk dropdowns.
2. A non-administrator doesn't see it, and gets an error at `users.php?page=glover-site-contacts`.
3. Choose a user for each and press Update: "Site Contacts Updated" shows and both choices are still selected.
4. `glover_site_webmaster()` and `glover_site_town_clerk()` return those users; clear both and they return null.

## Known Limitations

- If the chosen user is deleted, the setting quietly points at nothing and the helper returns null; the screen does not warn.

## Open Questions

Existing contact sources that could switch to the helper, none changed yet:

- **Document Library Settings, Help contact name, phone and email** (`glover_document_library_help_contact()` in `includes/features/document-library/document-library-public.php`). Free text with "Town Clerk's Office" as the default name. It is shown on the Documents help dialog and results, the Submit a Document sign-in panel and steps ("Email the Town Clerk at …"), the "Questions? Town Clerk:" footer of submitter emails. The email could default to the Town Clerk's account email when the field is blank.
- **Glover Google must-use plugin.** Sends all mail from the `GLOVER_GOOGLE_MAIL_FROM` alias, with Reply-To defaulting to it. That is the site's sending identity, not a person, so it should stay a constant. The sign-in page's "contact the Town Clerk's office" message is plain text with no address.
- A person's account email may be a personal address. Before any public page shows the Town Clerk's or Webmaster's account email, decide whether that is acceptable or whether the town's shared office addresses should stay as the public contact.

## Material Change History

- 2026-10-23: The account emails (`mu-plugins/glover-google/account-email.php`) name the Webmaster in their footer.
- 2026-10-23: Added the Site Contacts options page, its two User fields, and the helper. Nothing reads them yet.
- 2026-10-23: Moved the screen from Settings to the Users menu.
