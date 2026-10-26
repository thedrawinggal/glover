#!/bin/sh
# Package the local Glover site for a one-way move to a server.
#
# Usage: tools/export-for-server.sh https://new-site-address [output-dir]
#
# Produces a folder (default ~/Sites/glover-migrations/<date>/) containing:
#   database.sql   the local database with every local address rewritten to the
#                  new one (serialized data handled by WP-CLI), ready to import
#   uploads.tar.gz wp-content/uploads, including private pending documents
#   MANIFEST.txt   source and target addresses, git commit, versions, checksums,
#                  and the wp-config.php settings the server needs (names only)
#
# The local database is not changed. A fresh local backup is taken first.
# Theme and must-use plugin code are deployed from git, not from this bundle.
# Import steps: tools/README.md.

set -eu

NEW_URL="${1:-}"
SITE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
STAMP="$(date +%Y-%m-%d_%H%M%S)"
OUT="${2:-$HOME/Sites/glover-migrations/$STAMP}"
SOCKET="${MAMP_MYSQL_SOCKET:-/Applications/MAMP/tmp/mysql/mysql.sock}"
WP_CLI="${WP_CLI:-$(command -v wp)}"

case "$NEW_URL" in
    https://*|http://*) ;;
    *)
        echo "Usage: tools/export-for-server.sh https://new-site-address [output-dir]" >&2
        exit 1
        ;;
esac
NEW_URL="${NEW_URL%/}"

wp_local() {
    php -d mysqli.default_socket="$SOCKET" -d error_reporting=0 -d display_errors=0 \
        "$WP_CLI" --path="$SITE_DIR" "$@"
}

OLD_URL="$(wp_local option get home)"

if [ "$OLD_URL" = "$NEW_URL" ]; then
    echo "The new address is the same as the local one ($OLD_URL)." >&2
    exit 1
fi

echo "Backing up the local site first..."
"$SITE_DIR/tools/backup-local.sh"

mkdir -p "$OUT"
chmod 700 "$OUT"

echo "Exporting the database with $OLD_URL rewritten to $NEW_URL..."
# --export writes the changed copy to a file and leaves the local database alone.
# GUIDs are left as they are, per WordPress guidance.
wp_local search-replace "$OLD_URL" "$NEW_URL" \
    --all-tables-with-prefix --skip-columns=guid --precise \
    --export="$OUT/database.export.sql" --report-changed-only

# WP-CLI's export omits the session settings mysqldump adds. Without them,
# MySQL 8's strict mode rejects WordPress's zero-date column defaults.
{
    echo "SET NAMES utf8mb4;"
    echo "SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';"
    echo "SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;"
    cat "$OUT/database.export.sql"
    echo "SET SQL_MODE=@OLD_SQL_MODE;"
    echo "SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;"
} > "$OUT/database.sql"
rm -f "$OUT/database.export.sql"

echo "Packaging uploads..."
tar -czf "$OUT/uploads.tar.gz" -C "$SITE_DIR/wp-content" uploads

LEFTOVER="$(grep -c "$(printf '%s' "$OLD_URL" | sed 's/[.[\*^$/]/\\&/g')" "$OUT/database.sql" || true)"

{
    echo "Glover migration bundle"
    echo "Created:        $(date)"
    echo "From:           $OLD_URL"
    echo "To:             $NEW_URL"
    echo "Git branch:     $(git -C "$SITE_DIR" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
    echo "Git commit:     $(git -C "$SITE_DIR" rev-parse HEAD 2>/dev/null || echo unknown)"
    echo "Uncommitted:    $(git -C "$SITE_DIR" status --porcelain 2>/dev/null | wc -l | tr -d ' ') files (deploy committed code only)"
    echo "WordPress:      $(wp_local core version)"
    echo "ACF Pro:        $(wp_local plugin get advanced-custom-fields-pro --field=version 2>/dev/null || echo unknown)"
    echo "Old address left in database.sql: $LEFTOVER lines. Expected: one per post (GUIDs never change"
    echo "                by design) plus ACF's license record, which ACF rewrites itself."
    echo
    echo "Checksums (shasum -a 256):"
    (cd "$OUT" && shasum -a 256 database.sql uploads.tar.gz)
    echo
    echo "wp-config.php settings the server needs (values are NOT in this bundle):"
    grep -o "define( *'GLOVER_GOOGLE_[A-Z_]*'" "$SITE_DIR/wp-config.php" | sed "s/define( *'//; s/'$//" | sed 's/^/  /'
} > "$OUT/MANIFEST.txt"

chmod 600 "$OUT"/*

echo
cat "$OUT/MANIFEST.txt"
echo
echo "Bundle ready: $OUT"
echo "It contains private documents and user data. Keep it off email and public storage."
