# Login Screen

The WordPress login (`wp-login.php`) in the Glover style. It is the way in for anyone who works in the site: administrators, editors, and submitters following a link from an email. Submitters who start from Submit a Document sign in on that page instead and never see this screen.

## Status

Built and checked locally on 2026-10-23. Design: the "Glover Submit a Document" canvas, boards 8 (default) and 9 (password).

## Scope

- **Left side, the same on every screen** (`glover_login_intro_args()`, printed by `components/common/sign-in-intro.php`): a placeholder where the town logo will go, the "Town of Glover, Vermont" label, "Sign In" as the page heading and the Need access line, vertically centered beside the card from 992px.
- **Default screen:** the shared Sign-In Panel (`components/common/sign-in-panel.php`) on the cream page: the "Town of Glover, Vermont" label, "Sign In" as the page heading, the Need access line, then the card with an emailed code and Sign In With Google, and Sign In With Your Password Instead below. Every code step and error comes back to this screen. A signed-in visitor goes straight to where they were headed.
- **Opening screen:** the default screen, including for signed-out visits to `/wp-admin/` (which WordPress sends here with `reauth=1`; the screen clears any old sign-in cookie, as WordPress's own does). Once someone chooses Sign In With Your Password Instead, their browser remembers it for a year (`glover_login_method` cookie, `password`) and the login opens on the password screen; choosing Sign In With an Emailed Code there (`glover_method=code`) switches it back to `code`.
- **Password screen** (`wp-login.php?glover_password=1`) and WordPress's other login screens (lost password, reset password, login errors): WordPress's own forms and behavior, restyled to the same panel. The links under the form are Lost Your Password?, Sign In With an Emailed Code and Sign In With Google.
- "← Go to Town of Glover" sits under the panel on every screen.

## Non-goals

- No change to WordPress's authentication, password rules, reset flow or anything a security plugin adds to the password form.
- The admin's session-expired box (`interim-login`) keeps WordPress's standard screen, restyled. Its window is 760px wide, twice core's 380px, so the restyled screen fits; full width below 760px.
- No site header or footer on the login screens.

## How it works

- `login_init` (priority 5) saves a method chosen from a link: `glover_password=1` sets `glover_login_method` to `password`, `glover_method=code` sets it to `code` (a year, `SITECOOKIEPATH`, HTTP-only, Lax). Then it checks for the default screen: a GET request for the login action, without `glover_password` or `interim-login`, while the Glover Google Workspace must-use plugin is active. A remembered `password` sends it on to the password screen unless `glover_method=code` is present. It then prints its own page (with `wp_head()` and `wp_footer()`, so the theme's styles load) and stops. Without the plugin, WordPress's password form shows.
- The panel's forms post to the plugin's `/sign-in/` address with `glover_sign_in_back` set to this screen, so each step returns here with `glover_status` and `glover_step`. A finished sign-in goes to `redirect_to` (validated to this site), or the dashboard.
- The session-expired window itself (WordPress's auth check, outside the login page) is widened by an inline style on core's `wp-auth-check` stylesheet, added on `admin_enqueue_scripts` and `wp_enqueue_scripts`.
- On WordPress's own screens, `login_enqueue_scripts` adds the Glover fonts and `dist/styles/app.css`; `login_message` prints the shared left side, and `lost_password_html_link`, `login_site_html_link` and `login_footer` add the links and back link. WordPress's own h1 (its logo link) and the language picker are hidden. The side-by-side layout is a two-column grid from 992px with the left side spanning the rows beside everything else, since WordPress prints its login page as one flat list.

## File map

- `includes/features/login-screen/login-screen.php`: the default screen and the hooks on WordPress's own screens. Loaded from `functions.php`.
- `src/styles/pages/_login.scss`: both kinds of screen.
- The panel and its states: see the [components catalog](../components.md).

## Accessibility

The default screen's page heading is the panel's h1. On WordPress's own screens the shared left side's "Sign In" is the h1, and WordPress's own h1 is hidden (`display: none`), so each screen has one. The logo placeholder is hidden from screen readers; the real logo will need alt text naming the town. Focus rings are Orange on the forest panel, and errors on forest are Orange (see the spec plate's Errors on Forest).

## Verification

- `php -l` on the feature file.
- Checked signed out on 2026-10-23: the default screen renders the panel; a code request returns to the login with the code card; `/sign-in/` redirects here; the password and lost-password screens render with the Glover heading, links and back link; screenshots of both screens at 1024px match boards 8 and 9 apart from the icon, which WordPress's own screens don't show.

## Known limitations

- Sign In With Google from the password screen doesn't change the remembered method; only the two links between the screens do.
- WordPress adds a small help mark next to Remember Me.

## Material change history

- 2026-10-25: The session-expired sign-in window is 760px wide (core's 380px doubled), full width below 760px. Not yet checked in a browser with an expired session.

- 2026-10-23: `/wp-admin/` now opens on the default screen (emailed code and Google) instead of the password screen: `reauth=1` no longer sends people to WordPress's form. The login remembers the last method chosen with its links in the `glover_login_method` cookie. Checked with curl, signed out: `/wp-admin/` to the default screen; choosing the password screen sets `password`, after which `/wp-admin/` and a plain login open on it; Sign In With an Emailed Code sets `code` and the default screen returns.
- 2026-10-23: Every login screen now has the same left side, centered: a logo placeholder (replacing the document icon), the label, "Sign In" and the Need access line. WordPress's own screens get it through `login_message` and a grid layout instead of floats, and no longer name the screen in the heading. Checked at 1280px on the default screen (signed out), the password screen and Lost Password, and at 375px on Lost Password.
- 2026-10-23: Built the Glover login screen.
