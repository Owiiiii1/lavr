# Handover cleanup

Command: `lavr:handover-cleanup`

**Default: DRY RUN.** Nothing is deleted unless `--execute --confirm=HANDOVER`.

Requires a selector. No selector → refuse. No “delete all”.

```bash
/usr/bin/php8.5 artisan lavr:handover-cleanup --integration=123
/usr/bin/php8.5 artisan lavr:handover-cleanup --batch=val_abc --execute --confirm=HANDOVER
```

Take a backup first ([BACKUP_AND_RESTORE.md](BACKUP_AND_RESTORE.md)). The command warns.

## Plan then execute

The command prints a plan: what will be removed, what will be preserved. Reports store counts and selectors, **not** tokens or message bodies.

## Preserve by default

Confirmed People, Projects, Organizations, confirmed/open commitments, manually created canonical entities.

## May purge when selected

- `source_items` for that integration
- bindings for that source
- operational_events / proactive_proposals derived only from that source
- unconfirmed `detected` commitments whose only evidence is the removed source
- encrypted local tokens (remote revoke attempted for Google)

If a confirmed commitment also has evidence from another source, the commitment stays. Only the removed source’s evidence pointer is deleted.

## Telegram / Zoom

Bot global configuration is not deleted by integration cleanup. Group bindings and test source data can be. Zoom credentials can be removed separately from imported canonical Meetings.
