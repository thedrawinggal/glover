#!/bin/sh
# Back up the local Glover site: the database and wp-content/uploads
# (including the private documents folder). Keeps the newest 30 of each.
#
# Usage: tools/backup-local.sh
# Output: ~/Sites/db backups/glover/glover_<date>.sql.gz and glover_uploads_<date>.tar.gz
#
# wp-config.php and the Google key file are NOT included. They hold secrets
# and live outside the backup on purpose; see tools/README.md.

set -eu
# Fail if any step of a pipeline fails, so a broken dump is never kept.
set -o pipefail

SITE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${GLOVER_BACKUP_DIR:-$HOME/Sites/db backups/glover}"
SOCKET="${MAMP_MYSQL_SOCKET:-/Applications/MAMP/tmp/mysql/mysql.sock}"
MYSQLDUMP="${MYSQLDUMP:-/Applications/MAMP/Library/bin/mysql80/bin/mysqldump}"
KEEP="${GLOVER_BACKUP_KEEP:-30}"
STAMP="$(date +%Y-%m-%d_%H%M%S)"

config_value() {
    sed -n "s/^define( *'$1', *'\([^']*\)' *);.*/\1/p" "$SITE_DIR/wp-config.php" | head -n 1
}

DB_NAME="$(config_value DB_NAME)"
DB_USER="$(config_value DB_USER)"
DB_PASSWORD="$(config_value DB_PASSWORD)"

# MAMP's MySQL must be running. When it isn't (e.g. the scheduled run fires
# while MAMP is closed), say so and stop without leaving a broken backup.
if [ ! -S "$SOCKET" ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') Skipped: MAMP MySQL isn't running (no socket at $SOCKET). Start MAMP and run tools/backup-local.sh." >&2
    exit 2
fi

if [ -z "$DB_NAME" ]; then
    echo "Could not read DB_NAME from $SITE_DIR/wp-config.php" >&2
    exit 1
fi

mkdir -p "$DEST"
chmod 700 "$DEST"

SQL="$DEST/${DB_NAME}_$STAMP.sql.gz"
UPLOADS="$DEST/${DB_NAME}_uploads_$STAMP.tar.gz"

MYSQL_PWD="$DB_PASSWORD" "$MYSQLDUMP" \
    --socket="$SOCKET" --user="$DB_USER" \
    --single-transaction --default-character-set=utf8mb4 \
    --routines --triggers --no-tablespaces \
    "$DB_NAME" | gzip > "$SQL"

tar -czf "$UPLOADS" -C "$SITE_DIR/wp-content" uploads
chmod 600 "$SQL" "$UPLOADS"

# Keep the newest $KEEP of each kind.
prune() {
    { ls -1t "$DEST"/$1 2>/dev/null || true; } | tail -n +"$((KEEP + 1))" | while IFS= read -r old; do
        rm -f "$old"
    done
}
prune "${DB_NAME}_2*.sql.gz"
prune "${DB_NAME}_uploads_*.tar.gz"

echo "$(date '+%Y-%m-%d %H:%M:%S') Backup complete."
echo "Database: $SQL ($(du -h "$SQL" | cut -f1))"
echo "Uploads:  $UPLOADS ($(du -h "$UPLOADS" | cut -f1))"
