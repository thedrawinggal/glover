# Glover site tools: backups and the move to a server

The local MAMP site (`http://localhost:8888/glover`) is the **source of truth** until the site moves to its server. Real content goes in locally. The move happens **once, in one direction**. After it, all editing happens on the server and localhost becomes a development copy only.

## While building locally

Back up at the end of every work session, and before anything risky (an ACF sync, a plugin update, a bulk import):

```sh
tools/backup-local.sh
```

This saves the database and `wp-content/uploads` (including the private documents folder) to `~/Sites/db backups/glover/`, keeping the newest 30 of each. Backups are readable only by your Mac user.

**Automatic daily backup.** A macOS LaunchAgent runs `tools/backup-local.sh` every day at 5:30 PM, or as soon as the Mac wakes if it was asleep then. MAMP must be running; if it isn't, the run is skipped and logged. Check the log at `~/Library/Logs/glover-backup.log`.

- Installed copy: `~/Library/LaunchAgents/com.cultivate.glover-backup.plist` (a copy is kept in `tools/`; its paths assume this Mac's user folder)
- Run it now: `launchctl kickstart gui/$(id -u)/com.cultivate.glover-backup`
- Turn it off: `launchctl bootout gui/$(id -u)/com.cultivate.glover-backup`
- Turn it back on, or install it on a new Mac: copy the plist into `~/Library/LaunchAgents/` and run `launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.cultivate.glover-backup.plist`

**Not in backups, on purpose:** `wp-config.php` and the Google key file at `~/Sites/glover-secrets/`. They hold secrets. Keep them in your password manager or another safe place as well, since losing them means redoing parts of the Google setup.

To restore a backup into the local site, replacing what's there:

```sh
gunzip -c "$HOME/Sites/db backups/glover/glover_<date>.sql.gz" | MYSQL_PWD=root /Applications/MAMP/Library/bin/mysql80/bin/mysql -uroot -S /Applications/MAMP/tmp/mysql/mysql.sock glover
tar -xzf "$HOME/Sites/db backups/glover/glover_uploads_<date>.tar.gz" -C wp-content
```

## Choosing hosting

Open the hosting account **in the town's name with the town's payment method**, and add yourself as a user. Look for:

- PHP 8.x, SSH and SFTP, and WP-CLI
- A real server cron (for Drive backup retries)
- Apache, or nginx with a way to add a deny rule (see step 5)
- An upload limit of at least 64 MB
- Free SSL, daily backups, a staging copy, and a temporary address before the domain moves
- Ideally `pdftotext` installed, for full-text search later

## The move to the server

### 1. Prepare the server

1. Create the WordPress site on the host's temporary address, e.g. `https://glover.somehost.app`, with PHP 8.x.
2. Install ACF Pro and activate its license for the new address. Plugins aren't in git.
3. Deploy the code from git: `wp-content/themes/glover/` and `wp-content/mu-plugins/` (`glover-google.php` and the `glover-google/` folder). `dist/` is committed, so no build is needed on the server.
4. Keep the server **private while testing**: turn on the host's password protection and set *Settings > Reading > Discourage search engines*.

### 2. Freeze and export

1. Stop making changes locally.
2. Commit and push any code changes. The manifest records the commit.
3. Run, with the server's address:

```sh
tools/export-for-server.sh https://glover.somehost.app
```

This takes a fresh backup, then writes a bundle to `~/Sites/glover-migrations/<date>/`:

- `database.sql`: every local address rewritten to the new one, with serialized data handled safely, and MySQL 8 settings included
- `uploads.tar.gz`
- `MANIFEST.txt`: addresses, git commit, versions, checksums, and the names of the `wp-config.php` settings the server needs

The bundle holds private documents and user data. Move it by SFTP or `scp`, never by email or public storage.

### 3. Import on the server

Over SSH, in the site's WordPress folder, with the bundle uploaded **outside the web root**:

```sh
shasum -a 256 ~/bundle/database.sql ~/bundle/uploads.tar.gz   # compare with MANIFEST.txt
wp db export ~/before-import.sql                               # safety copy of the empty site
wp db import ~/bundle/database.sql
tar -xzf ~/bundle/uploads.tar.gz -C wp-content
wp rewrite flush
wp transient delete --all
wp cache flush
```

The local `$table_prefix` is `wp_`. The server's `wp-config.php` must use the same prefix. After the import, sign in with your local account, since the users come across.

### 4. Server settings

1. Add the `GLOVER_GOOGLE_*` lines from local `wp-config.php` to the server's `wp-config.php`. `MANIFEST.txt` lists their names. Copy the key file to a folder **outside the web root** (e.g. `~/glover-secrets/`), `chmod 600` it, and update `GLOVER_GOOGLE_SERVICE_ACCOUNT_KEY_FILE` to the new path.
2. In Google Cloud (Glover Website project > Google Auth Platform > Clients > Glover website sign-in), add the redirect URI `https://<new-address>/sign-in/google/callback/`.
3. Real cron: add `define('DISABLE_WP_CRON', true);` to `wp-config.php`, and a server cron job every 5 minutes: `cd /path/to/site && wp cron event run --due-now`.
4. Raise PHP `upload_max_filesize` and `post_max_size` if the host's limit is low.

### 5. Check the private folder is blocked

```sh
curl -I https://<new-address>/wp-content/uploads/glover-private-documents/index.php
```

It must return **403**. On nginx, add this to the server config if it doesn't:

```nginx
location ^~ /wp-content/uploads/glover-private-documents/ { deny all; }
```

### 6. Test on the server

- Settings > Google Workspace: send a test email and test Drive access.
- Sign in with Google, and with an emailed code, in a private window.
- Submit a test document, approve it, and confirm it appears in the library and reaches Drive.
- `/documents/` filters, a board page, the request form, and a phone-width check.
- Remove local-only accounts you don't want on the server (e.g. `glover_admin`).

From here, **the server is the source of truth.** Localhost is for development only. To refresh it, export the server database and run `wp search-replace` back to `http://localhost:8888/glover`.

## Launch day: moving townofglover.com

1. On the server: `wp search-replace 'https://glover.somehost.app' 'https://townofglover.com' --all-tables --skip-columns=guid`, then flush rewrites and caches.
2. **DNS: change only the website records** (the `@` A/AAAA record and `www`) to the new host. **Leave the Google email records exactly as they are**: MX, SPF (`v=spf1 include:_spf.google.com ~all`), DKIM (`google._domainkey`) and DMARC. Changing them breaks town email.
3. Add `https://townofglover.com/sign-in/google/callback/` to the Google sign-in client, and re-activate ACF Pro for the domain.
4. Turn off password protection and *Discourage search engines*.
5. Keep the old site's hosting until the new site has run cleanly for a couple of weeks, then cancel it.
