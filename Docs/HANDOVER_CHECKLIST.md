# Handover checklist

Do not execute this list in Phase 12 except as documentation. Do not reset the Owner password automatically. Do not remove SSH/GitHub access automatically.

Owner/Admin is the **same user** on this single-Owner instance. That is intentional.

WARN: Owner credential rotation is required before transfer. Do not record the current password here.

- [ ] Backups taken and restore steps understood ([BACKUP_AND_RESTORE.md](BACKUP_AND_RESTORE.md))
- [ ] Git `main` clean, origin `Owiiiii1/lavr`
- [ ] Migrations ran (`/usr/bin/php8.5 artisan migrate:status`)
- [ ] `APP_DEBUG=false`
- [ ] Client Owner account exists; password changed from temporary/admin value
- [ ] Review/remove developer SSH and GitHub access if requested
- [ ] Developer OAuth (Google) removed via `lavr:handover-cleanup --integration=…` dry-run then explicit execute
- [ ] Developer Telegram bindings removed (bot config kept if the same bot stays)
- [ ] Developer Zoom credentials removed; canonical meetings preserved unless provenance says otherwise
- [ ] Developer API keys (AI/search/voice) not handed to the client
- [ ] Synthetic validation batches removed (`lavr:validation-cleanup`)
- [ ] Integrations client-owned
- [ ] Email / domain / DNS / SSL ownership
- [ ] Telegram bot ownership
- [ ] Google OAuth app ownership
- [ ] Zoom app ownership
- [ ] AI billing ownership
- [ ] Backup ownership
- [ ] `lavr:diagnostics` and `lavr:production-smoke` PASS/WARN reviewed
- [ ] Final smoke: login, `/lavr/today`, `/register` 404

Cleanup command: [HANDOVER_CLEANUP.md](HANDOVER_CLEANUP.md).
