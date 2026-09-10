# Data sources

Canonical source map. Bindings: [PROJECTS.md](PROJECTS.md). Telegram groups implementation: [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md). Integrations code: [INTEGRATIONS.md](INTEGRATIONS.md).

LAVR reads sources. It does not replace Gmail, Calendar, Telegram, or Zoom as systems of record for those products.

## Language of source content

Source content is stored in the **original language**. Translation is a presentation / AI function, not a transformation of the source of truth.

Do **not** translate on save:

- emails;
- Telegram messages;
- transcripts;
- documents;
- meeting artifacts (including original Zoom / uploaded transcripts).

Names of people, organizations, projects, files, and original quotes stay as received. Do not auto-localize them.

On-demand translation for the Owner (UI or assistant) must not overwrite the stored artifact. If a translation is unavailable, fallback language is Ukrainian. [PRODUCT.md](PRODUCT.md#languages).

---

## CURRENT

| Source | Status |
| --- | --- |
| Gmail / Google Workspace | OAuth `IntegrationAccount`; tools; **no mailbox mirror**; send requires confirmation. Typically **one** active Google account (ADR-070). Live campaign not fully MANUAL PASS. |
| Calendar | Live Google Calendar; no local event table. |
| Telegram private bot | Webhook, pairing, DM text/voice. |
| Telegram groups | Persist + analysis tools; Owner; campaign not fully validated. |
| Uploaded documents | Storage / attachments / Knowledge ingest. |
| Manual meeting transcripts | **IMPLEMENTED (Phase 5A).** Private `meeting_artifacts` (.txt/.vtt/.srt/.md or pasted text). Not Knowledge documents. |
| GitHub | OAuth + tools + watcher source; not a CEO ops source of first importance. |
| Zoom | **IMPLEMENTED / LIVE E2E NOT VALIDATED.** S2S OAuth + `recording.transcript_completed` → Meeting. Manual upload remains fallback. |
| External dashboards / APIs | **Not** integrated (Phase 10). |

Permissions: Owner/client account owns integrations. Encrypted credentials. Tool confirmation for external writes.

Scheduled report collectors are **independent**. Gmail or Calendar failure returns a safe section error; the rest of the report still delivers (`partial`). Revoked Gmail auth is `blocked`, not an endless retry. [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md).

---

## TARGET sources

- Gmail / Google Workspace ( **multiple mailboxes**, each bound to Project / Organization / purpose )
- Calendar (bound to context where possible)
- Telegram groups (source + policy)
- Telegram private bot (CEO channel)
- Zoom (OAuth + `recording.transcript_completed` → Meeting) — [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md)
- Manual / other conferencing transcripts (upload fallback, Phase 5A) — **IMPLEMENTED**
- Uploaded documents
- Future external APIs
- Internal dashboards (read via API, do not clone ERP/CRM)

### Zoom

**CURRENT:** Phase 5B ingest is in code. **LIVE ZOOM E2E: NOT VALIDATED** (no Owner Zoom credentials exercised on this host).

App type: Server-to-Server OAuth for one dedicated Zoom account. Grant: `account_credentials`. Token cached until expiry minus skew; re-requested, never stored in the database.

Scopes (current Zoom granular, account-level):

- `cloud_recording:read:meeting_transcript:admin`
- `cloud_recording:read:list_recording_files:admin`
- `user:read:user:admin` (Test Connection via `GET /users/me`)

Webhook URL: `https://lavr.youngfashionshow.com/webhooks/zoom` (`POST /webhooks/zoom`). CRC `endpoint.url_validation`. Signature: `x-zm-signature` = `v0=` HMAC-SHA256 of `v0:{timestamp}:{rawBody}` with the webhook secret token. Replay window 5 minutes. Account id allowlist when configured.

Canonical transcript-ready event: `recording.transcript_completed`. `recording.completed` is telemetry only.

Transcript API: `GET /meetings/{meetingId}/transcript` (meeting UUID, double-encoded). Download with OAuth Bearer token. Trusted hosts only (`zoom.us` / `*.zoom.us` / `zoom.com` / `zoomgov.com`). Max size matches meeting transcript limits. 404/NOT_READY bounded retry. 429 honors `Retry-After`. 401 → `blocked_auth`.

Meeting match: `source_type=zoom` + `source_external_id` = Zoom meeting **UUID** (not numeric meeting id; recurring instances share the numeric id).

Import statuses in `meetings.metadata.zoom.import_status`: pending / downloading / processing / completed / failed / blocked_auth / transcript_unavailable.

Automatic Zoom import does not replace manual upload. It does not silently set `project_id`.

Setup steps: [Development/LAVR_PHASE_5B_REPORT.md](Development/LAVR_PHASE_5B_REPORT.md).

### Project binding

Every mailbox, calendar, Telegram group, and Zoom/conferencing account should declare: project, people, purpose, importance, monitoring policy. Zoom meetings may still land **unresolved** until confirmation. [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md).

Unbound sources are allowed only as a temporary onboarding state; they should not silently pollute every brief.

### Telegram limitations (document, do not ignore)

Telegram bots cannot freely read all historical group messages the way a user client can. Privacy mode, bot membership, and `getUpdates`/`webhook` constraints apply. LAVR analyses **what the bot actually receives**. Historical backfill may be incomplete. Product copy and onboarding must not promise a full group archive.

### Permissions / policy

- Read vs write separated.
- Writes to third parties (mail employees, post in groups) need confirmation or an explicit automation policy.
- Do not store provider dumps in CEO-facing bodies.
