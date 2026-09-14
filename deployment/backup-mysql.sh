#!/usr/bin/env bash
set -euo pipefail

# Run from a protected server account. Put DB credentials in the environment,
# never in this script. Example: DB_HOST, DB_NAME, DB_USER, DB_PASS, BACKUP_DIR.
: "${DB_HOST:?Set DB_HOST}"; : "${DB_NAME:?Set DB_NAME}"; : "${DB_USER:?Set DB_USER}"; : "${DB_PASS:?Set DB_PASS}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/isokoryacu}"
mkdir -p "$BACKUP_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="$BACKUP_DIR/${DB_NAME}-${STAMP}.sql.gz"
export MYSQL_PWD="$DB_PASS"
mysqldump --single-transaction --quick --routines --triggers --events -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" | gzip -9 > "$OUT"
unset MYSQL_PWD
find "$BACKUP_DIR" -type f -name '*.sql.gz' -mtime +14 -delete
printf 'Backup written: %s\n' "$OUT"
