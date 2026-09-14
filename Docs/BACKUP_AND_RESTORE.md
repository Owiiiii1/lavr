# Backup and restore

LAVR-only. Do not dump other databases on this host.

## Scope

- MySQL database `lavr`
- Private storage `storage/app` (meeting artifacts, uploads)
- `.env` / secrets — **not** stored in Git; copy offline
- Not reconstructable: uploaded meeting artifacts, encrypted integration tokens

## Backup

Dry-run:

```bash
/usr/bin/php8.5 artisan lavr:backup --dry-run=1
```

Script (credentials from `.env`, never printed):

```bash
/var/www/lavr/scripts/lavr-backup.sh
```

Output: `storage/backups/` (gitignored).

## Restore

1. Put the host in maintenance if needed (`php artisan down`) — LAVR only.
2. Restore MySQL: `mysql lavr < lavr-db-….sql`
3. Extract storage archive into `/var/www/lavr`
4. Permissions: `storage/` and `bootstrap/cache` writable by the php8.5-fpm user
5. `/usr/bin/php8.5 artisan migrate --force`
6. `/usr/bin/php8.5 artisan queue:restart`
7. Smoke: `/usr/bin/php8.5 artisan lavr:production-smoke` and login + `/lavr/today`

Do not restore onto another site’s database. Do not test restore by destroying production.

## Retention (current, not aggressive)

Documented only: `source_items` kept; logs/tool logs retained until an explicit purge; meeting artifacts until deleted; validation batches until `lavr:validation-cleanup`; `proactive_proposals` / `operational_events` kept for operational history.
