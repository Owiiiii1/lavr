# Data sources

Canonical source map. Bindings: [PROJECTS.md](PROJECTS.md). Telegram groups implementation: [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md). Integrations code: [INTEGRATIONS.md](INTEGRATIONS.md).

LAVR reads sources. It does not replace Gmail, Calendar, Telegram, or Zoom as systems of record for those products.

---

## CURRENT

| Source | Status |
| --- | --- |
| Gmail / Google Workspace | OAuth `IntegrationAccount`; tools; **no mailbox mirror**; send requires confirmation. Typically **one** active Google account (ADR-070). Live campaign not fully MANUAL PASS. |
| Calendar | Live Google Calendar; no local event table. |
| Telegram private bot | Webhook, pairing, DM text/voice. |
| Telegram groups | Persist + analysis tools; Owner; campaign not fully validated. |
| Uploaded documents | Storage / attachments / Knowledge ingest. |
| GitHub | OAuth + tools + watcher source; not a CEO ops source of first importance. |
| Zoom transcripts | **Not** a meeting import pipeline. |
| External dashboards / APIs | **Not** integrated (Phase 10). |

Permissions: Owner/client account owns integrations. Encrypted credentials. Tool confirmation for external writes.

---

## TARGET sources

- Gmail / Google Workspace ( **multiple mailboxes**, each bound to Project / Organization / purpose )
- Calendar (bound to context where possible)
- Telegram groups (source + policy)
- Telegram private bot (CEO channel)
- Zoom transcripts → [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md)
- Uploaded documents
- Future external APIs
- Internal dashboards (read via API, do not clone ERP/CRM)

### Project binding

Every mailbox, calendar, and Telegram group should declare: project, people, purpose, importance, monitoring policy.

Unbound sources are allowed only as a temporary onboarding state; they should not silently pollute every brief.

### Telegram limitations (document, do not ignore)

Telegram bots cannot freely read all historical group messages the way a user client can. Privacy mode, bot membership, and `getUpdates`/`webhook` constraints apply. LAVR analyses **what the bot actually receives**. Historical backfill may be incomplete. Product copy and onboarding must not promise a full group archive.

### Permissions / policy

- Read vs write separated.
- Writes to third parties (mail employees, post in groups) need confirmation or an explicit automation policy.
- Do not store provider dumps in CEO-facing bodies.
