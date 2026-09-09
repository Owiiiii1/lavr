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
| Zoom | **TARGET Phase 5B / NOT IMPLEMENTED.** Not a meeting import pipeline today. |
| External dashboards / APIs | **Not** integrated (Phase 10). |

Permissions: Owner/client account owns integrations. Encrypted credentials. Tool confirmation for external writes.

---

## TARGET sources

- Gmail / Google Workspace ( **multiple mailboxes**, each bound to Project / Organization / purpose )
- Calendar (bound to context where possible)
- Telegram groups (source + policy)
- Telegram private bot (CEO channel)
- Zoom (OAuth + `recording.transcript_completed` → Meeting) — [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md)
- Manual / other conferencing transcripts (upload fallback, Phase 5A)
- Uploaded documents
- Future external APIs
- Internal dashboards (read via API, do not clone ERP/CRM)

### Zoom

**CURRENT:** not implemented. No Zoom OAuth app, webhook, or transcript import in this repository.

**TARGET:** Phase 5B, immediately after Phase 5A (manual Meetings + transcript upload). Goal: after a Zoom meeting ends and Zoom finishes the cloud transcript, LAVR creates/updates a Meeting without a manual upload.

App model: a Zoom OAuth application suitable for the **client’s actual Zoom account**. Confirm app type and scopes against **current Zoom documentation** before coding. Do not treat an unconfirmed OAuth model as final. Scopes must be enough for:

- webhook subscriptions;
- cloud recording transcript metadata;
- meeting transcript read/download.

Webhook: `recording.transcript_completed`. Validate authenticity per the current Zoom webhook protocol; check event type; extract account, meeting id/uuid, host, transcript/recording metadata; deduplicate; enqueue an import job; return **HTTP 200 or 204** immediately. Do not run AI or download the transcript on the webhook request.

Transcript API (verify at implementation time): `GET /meetings/{meetingId}/transcript` → metadata + `download_url`. Backend downloads with the Zoom OAuth access token. No Zoom credentials in the frontend.

Import statuses: `pending` / `downloading` / `processing` / `completed` / `failed` / `blocked_auth` / `transcript_unavailable`. Transcript-not-ready is not a permanent failure. Expired auth is a diagnosable `blocked_auth` state. Bounded retries only.

Original Zoom transcript is the source artifact. AI summary/decisions/commitments are derived. [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md).

### Project binding

Every mailbox, calendar, Telegram group, and Zoom/conferencing account should declare: project, people, purpose, importance, monitoring policy. Zoom meetings may still land **unresolved** until confirmation. [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md).

Unbound sources are allowed only as a temporary onboarding state; they should not silently pollute every brief.

### Telegram limitations (document, do not ignore)

Telegram bots cannot freely read all historical group messages the way a user client can. Privacy mode, bot membership, and `getUpdates`/`webhook` constraints apply. LAVR analyses **what the bot actually receives**. Historical backfill may be incomplete. Product copy and onboarding must not promise a full group archive.

### Permissions / policy

- Read vs write separated.
- Writes to third parties (mail employees, post in groups) need confirmation or an explicit automation policy.
- Do not store provider dumps in CEO-facing bodies.
