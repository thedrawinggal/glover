# Google Workspace integration

## Status

Live and verified against real Google on 2026-10-20 on the local site. Production still needs its own settings (see the end of the checklist). Covers Phases 2 and 3 of the [Document Library](document-library.md), plus site-wide email:

- **Site email** through the Gmail API, sent from webmaster@townofglover.com.
- **Sign-in** for document submitters: "Sign in with Google" for townofglover.com accounts, and an emailed one-time code for everyone else.
- **Drive backup** of every approved document to a town-owned Shared Drive.

Each part switches on only when its settings exist in `wp-config.php`. Until then the site behaves exactly as before: normal `wp_mail()`, the WordPress login form, and Drive status "Not configured".

Last updated: 2026-10-20.

## Decisions

- **Sender:** mail is sent *from* the Google Group webmaster@townofglover.com. The group's Who can post setting must include External so Google's confirmation and residents' replies arrive, so replies reach the group's members. Google can't send as a group directly, so the site sends through one staff mailbox that has webmaster@ set up as a "Send mail as" alias. That mailbox's Sent folder will show the site's messages.
- **Method:** the Gmail API with a service account and domain-wide delegation, limited to the `gmail.send` scope. No mail passwords are stored.
- **Drive:** the same service account is a Content manager on the Shared Drive. No delegation is needed for Drive.
- **Sign-in:** a Google OAuth web client with an Internal consent screen, so only townofglover.com accounts can use it. Emailed codes cover everyone else. Both only sign in people who already have a WordPress account with a role. Removing someone's role signs them out of both.
- **No libraries:** every Google call is a plain HTTPS request made with WordPress's HTTP API. No Composer, no paid add-ons.
- **Secrets** live only in `wp-config.php` and a key file outside the web root. Never put them in the repo, in a ticket, or in chat.

## Setup checklist

Do these in order. Steps 1 to 3 are in the Google Admin console (admin.google.com). Steps 4 to 9 are in the Google Cloud console (console.cloud.google.com), signed in as a townofglover.com admin.

1. **Create the group.** Directory > Groups > Create group: name "Town of Glover Website", email `webmaster@townofglover.com`. Add the people who should see replies. Under Access settings, set "Who can post" to *Anyone on the web* so residents' replies arrive.
2. **Choose the sending mailbox** (a staff account, e.g. the clerk's). Signed in as that person in Gmail: Settings > See all settings > Accounts > "Send mail as" > Add another email address. Name: *Town of Glover*, address: `webmaster@townofglover.com`, keep "Treat as an alias" checked. Google emails a confirmation to the group; open it from any member's inbox and confirm.
3. **Check Shared Drives are allowed.** Apps > Google Workspace > Drive and Docs > Sharing settings > Shared drive creation should be allowed for your account.
4. **Create a Cloud project.** Project picker > New project: name "Glover Website", organization: townofglover.com.
5. **Enable two APIs.** APIs & Services > Library: enable **Gmail API** and **Google Drive API**.
6. **Create the service account.** IAM & Admin > Service accounts > Create: name `glover-website`. Skip the optional role and access steps. Open it and note two values: its **email** (ends in `iam.gserviceaccount.com`) and its **Unique ID** (a long number).
   - Keys > Add key > Create new key > JSON. Save the downloaded file as `~/Sites/glover-secrets/glover-service-account.json`. This folder is outside the web root. Don't email or upload the file.
   - If key creation is blocked by "iam.disableServiceAccountKeyCreation", go to IAM & Admin > Organization policies, open *Disable service account key creation*, and override it for this project only (Not enforced). Then create the key.
7. **Allow it to send mail.** Back in the Admin console: Security > Access and data control > API controls > Manage domain-wide delegation > Add new. Client ID: the service account's **Unique ID**. OAuth scope: `https://www.googleapis.com/auth/gmail.send`. Authorize.
8. **Create the Shared Drive.** In Google Drive: Shared drives > New: "Glover Website Documents". Manage members: add the **service account email** as *Content manager*. Open the drive and copy its ID from the address bar (the part after `/folders/`).
9. **Set up sign-in.** In the Cloud console, under Google Auth Platform (or APIs & Services > OAuth consent screen):
   - Branding: app name "Town of Glover Website", support email `webmaster@townofglover.com`.
   - Audience: **Internal**.
   - Clients > Create client > Web application, name "Glover website sign-in". Authorized redirect URIs: `http://localhost:8888/glover/sign-in/google/callback/`. Add the production address later, e.g. `https://townofglover.com/sign-in/google/callback/`.
   - Copy the **Client ID** and **Client secret**.
10. **Add the settings to `wp-config.php`** above the line `/* That's all, stop editing! */`:

```php
// Google Workspace (see wp-content/themes/glover/docs/features/google-workspace.md)
define('GLOVER_GOOGLE_DOMAIN', 'townofglover.com');
define('GLOVER_GOOGLE_SERVICE_ACCOUNT_KEY_FILE', '/Users/jennyschuler/Sites/glover-secrets/glover-service-account.json');
define('GLOVER_GOOGLE_MAIL_SENDER', 'clerk@townofglover.com');      // the mailbox from step 2
define('GLOVER_GOOGLE_MAIL_FROM', 'webmaster@townofglover.com');
define('GLOVER_GOOGLE_MAIL_FROM_NAME', 'Town of Glover');
define('GLOVER_GOOGLE_DRIVE_ID', 'paste-the-shared-drive-id');
define('GLOVER_GOOGLE_OAUTH_CLIENT_ID', 'paste-the-client-id');
define('GLOVER_GOOGLE_OAUTH_CLIENT_SECRET', 'paste-the-client-secret');
```

11. **Test it.** In wp-admin, Settings > Google Workspace shows which parts are configured and has buttons to send a test email and test Drive access.

On production, put the key file outside `public_html`, update the path and redirect URI, and add the production redirect URI to the OAuth client.

## How it works

**Email.** `pre_wp_mail` hands every `wp_mail()` call to `glover_google_send_mail()` once mail is configured. The service account signs a JWT that acts as `GLOVER_GOOGLE_MAIL_SENDER` with only the `gmail.send` scope, swaps it for an access token (cached for about an hour), and posts the raw message to the Gmail API. Messages are From and Reply-To webmaster@. Plain text and HTML, Cc, Bcc and attachments are supported. A failure fires `wp_mail_failed` and is written to the PHP error log.

**Sign-in** (`/sign-in/`):

- *Google:* `/sign-in/google/` stores a random state in a browser-bound session (an HttpOnly cookie plus a transient) and redirects to Google with `hd=townofglover.com`. `/sign-in/google/callback/` checks the state, exchanges the code with the client secret, and checks the ID token's audience, issuer, expiry, verified email and Workspace domain. It then signs in the WordPress user with that email.
- *Emailed code:* the visitor enters an email address. If it belongs to a user with a role, a 6-digit code is emailed. The code is stored only as an HMAC, expires in 10 minutes and works once. Five wrong tries lock the address for 15 minutes. Requests are limited to 3 per address per 15 minutes and 10 per IP per hour. The page always gives the same answer, so it never reveals who has an account.
- Both methods sign people in for 30 days. Only existing WordPress users with a role can sign in. To revoke access, change the person's role to "No role for this site".
- The Document Library's sign-in links (`glover_document_library/sign_in_url`) point here. `wp-login.php` still works for administrators.

**Drive backup.** When a document is published or replaced, the library fires `backup_requested`. The Drive module schedules a WP-Cron job, which finds or creates `Board / Year / Category` folders in the Shared Drive (folder IDs are cached, and a cached folder is checked before use, so folders deleted or trashed in Drive are recreated) and uploads the file with a resumable upload. The file is named like `2026-10-14_select-board_minutes.pdf`, with `_v2` and up for replacements. The Drive file ID is saved on the document. Failures retry 4 more times at 15, 30, 45 and 60 minutes, then show as Failed on the document and in an admin notice, with a Retry button.

## File map

- `wp-content/mu-plugins/glover-google.php`: loader (a must-use plugin, so a theme change can't break sign-in or email).
- `wp-content/mu-plugins/glover-google/client.php`: settings, service account key, JWT access tokens, request helper.
- `wp-content/mu-plugins/glover-google/mail.php`: Gmail API transport for `wp_mail()`.
- `wp-content/mu-plugins/glover-google/sign-in.php`: `/sign-in/` routes, Google OAuth, emailed codes, 30-day sessions.
- `wp-content/mu-plugins/glover-google/status.php`: Settings > Google Workspace status and test buttons.
- `wp-content/themes/glover/includes/features/document-library/document-library-drive.php`: Drive backup handler, folders, upload, retries, failure notice.
- `wp-content/themes/glover/src/styles/pages/_sign-in.scss`: sign-in page styles.
- `.gitignore`: tracks only the Glover must-use plugin under `wp-content/mu-plugins/`.

## Verification

- `php -l` on every file.
- A WP-CLI script that mocks Google's endpoints with `pre_http_request` and uses a real throwaway RSA key. 45 of 45 checks passed on 2026-10-20: configuration detection, JWT signature and claims, token caching, message headers and encoding, the code flow (unknown and role-less addresses, hashing, single use, lockout, rate limit), ID token claim checks, the library sign-in link, 30-day sessions, and Drive queueing, folders, naming, upload, caching, retry and failure.
- HTTP checks of `/sign-in/` (renders, `noindex`) and of the Google route falling back when not configured.
- **Verified against real Google on 2026-10-20:** a test email sent from webmaster@ through jenny@townofglover.com; a real Drive backup into `Select Board / 2026 / Minutes` with the copy matching the server file byte for byte (test file and folders then moved to the drive's trash); and a full "Sign in with Google" round trip by Jenny as a Document Submitter.

## Local configuration (2026-10-20)

- Sending mailbox: `jenny@townofglover.com`, with webmaster@ verified as a "Send mail as" alias. Before launch, consider moving this to a long-term town staff mailbox: change `GLOVER_GOOGLE_MAIL_SENDER` and add the alias in that person's Gmail.
- Service account: `glover-website@glover-website-512214.iam.gserviceaccount.com` (Unique ID 116955177061542909689), domain-wide delegation for `gmail.send` only.
- Shared Drive: "Glover Website Documents" (`0AFKH6dxADVvqUk9PVA`), service account as Content manager.
- OAuth client "Glover website sign-in", Internal audience, local redirect URI registered. The first client secret was exposed in a chat and replaced; the old one should stay deleted.
- Local test account: `jenny@townofglover.com` as a Document Submitter for Select Board.

## Known limitations and risks

- Drive backups run on WP-Cron, which only runs when the site gets visits. On production, a real server cron should call `wp-cron.php` every few minutes.
- Gmail sending limits apply to the sender mailbox (about 2,000 messages a day on Workspace), far above this site's needs.
- If the sender mailbox is deleted or loses its webmaster@ alias, site email stops. Sign-in codes stop with it, and Google sign-in still works.
- An Internal consent screen means only townofglover.com accounts can use "Sign in with Google". Everyone else uses codes.
- The service account key is a long-lived secret. Keep it outside the web root, and rotate it (create a new key, delete the old one) if it is ever exposed.
- Site email depends on the Gmail settings; without them, WordPress falls back to PHP mail, which won't deliver from MAMP.

## Material change history

- 2026-10-20: Cached Drive folders are now checked before use, so a folder deleted in Drive is recreated on the next backup. First real submission (#497) went through submitted, approved, published and backed up.
- 2026-10-20: Completed setup in Google Admin and Cloud for the local site and verified email, Drive backup and Google sign-in live.
- 2026-10-20: Built the Glover Google Workspace must-use plugin (Gmail API email from webmaster@, `/sign-in/` with Google and emailed codes, status and test screen) and the Document Library's Drive backup module, with the setup checklist.
