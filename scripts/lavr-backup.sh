#!/usr/bin/env bash
# LAVR backup helper. Credentials stay in /var/www/lavr/.env — never print them.
# Usage: ./scripts/lavr-backup.sh
set -euo pipefail
ROOT="/var/www/lavr"
cd "$ROOT"
DEST="${ROOT}/storage/backups"
mkdir -p "$DEST"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
# Load env without echoing.
set -a
# shellcheck disable=SC1091
source "$ROOT/.env"
set +a
DUMP="${DEST}/lavr-db-${STAMP}.sql"
STORAGE_ARCHIVE="${DEST}/lavr-storage-${STAMP}.tar.gz"
mysqldump --single-transaction --routines --triggers \
  -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" -u"${DB_USERNAME}" -p"${DB_PASSWORD}" "${DB_DATABASE}" > "${DUMP}"
tar -czf "${STORAGE_ARCHIVE}" -C "${ROOT}" storage/app
echo "Wrote ${DUMP} and ${STORAGE_ARCHIVE}"
echo "Restore: see Docs/BACKUP_AND_RESTORE.md"
