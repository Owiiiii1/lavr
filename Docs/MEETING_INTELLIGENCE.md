# Meeting intelligence

Canonical meeting model. Commitments: [COMMITMENTS.md](COMMITMENTS.md). Leadership quality: [LEADERSHIP_REVIEW.md](LEADERSHIP_REVIEW.md). Sources: [DATA_SOURCES.md](DATA_SOURCES.md). Plan: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md).

## CURRENT

First-class Meetings exist (Phase 5A, 2026-09-09).

| Piece | Status |
| --- | --- |
| `meetings` / `meeting_participants` / `meeting_artifacts` / `meeting_analyses` | **IMPLEMENTED** |
| Manual file upload `.txt` `.vtt` `.srt` `.md` | **IMPLEMENTED** |
| Pasted transcript text | **IMPLEMENTED** |
| Private disk storage (same `local` disk as Storage, path `meetings/{user}/{meeting}/…`) | **IMPLEMENTED** |
| Meeting Intelligence queue (`analysis`) + versioned JSON | **IMPLEMENTED** |
| Workspace `/lavr/meetings` + Admin `/meetings` | **IMPLEMENTED** |
| AI read tools `list_meetings` `find_meeting` `get_meeting` `get_meeting_analysis` | **IMPLEMENTED** |
| First-class `commitments` from `commitments_detected` | **IMPLEMENTED** (Phase 6; high confidence → `detected`; medium/low stay suggestions) |
| First-class `decisions` rows | **NOT** — analysis JSON only |
| Zoom OAuth / webhook / cloud transcript ingest | **IMPLEMENTED / LIVE E2E NOT VALIDATED** |
| Google Calendar | Live external source (no local event mirror) — ADR-072. Optional `source_external_id` on Meeting; no auto ingest |
| Knowledge events | Index only — not a Meeting |

A Zoom or manual transcript must **not** be stored only as a Knowledge document. Original artifact is source of truth for words. Analysis is derived.

Detail: [Development/LAVR_PHASE_5A_REPORT.md](Development/LAVR_PHASE_5A_REPORT.md), [Development/LAVR_PHASE_5B_REPORT.md](Development/LAVR_PHASE_5B_REPORT.md).

---

## TARGET

`meetings` is a first-class operational entity.

Store at least:

- title, date/time
- participants (People)
- project (nullable until confirmed)
- original transcript (source of truth for words)
- transcript source (Zoom, manual upload, …)
- provider metadata and external identifiers
- import status
- summary, topics
- decisions, extracted commitments, tasks
- open questions, risks
- source links

AI-generated fields must reference the meeting (and preferably transcript location). They are **derived**. The original transcript is the source artifact.

### Ingestion: manual vs automatic

| Path | Phase | Role |
| --- | --- | --- |
| Manual transcript upload | **5A** | Permanent fallback |
| Zoom cloud transcript after the meeting | **5B** | Automatic; no CEO upload |

Manual import stays forever. It is required for:

- older Zoom meetings;
- meetings not on the CEO’s Zoom account;
- Google Meet;
- Teams;
- transcripts from other people;
- `.txt`, `.vtt`, and other supported formats.

Automatic Zoom import must not replace that fallback.

### Phases

**Phase 5A — Meetings + Manual Transcript Import.** **IMPLEMENTED.** First-class `meetings`, participants, project binding, manual upload/paste, original transcript storage, analysis (topics, summary, decisions, action items, detected commitments, open questions, risks). Leadership Review product slice remains Phase 9. SQL lives in `database/migrations/2026_09_09_160000_create_meetings_tables.php`.

**Phase 5B — Zoom Integration.** **IMPLEMENTED / LIVE E2E NOT VALIDATED.** After 5A. Zoom meetings appear in LAVR when the cloud transcript is ready, without a manual upload. Manual upload remains the permanent fallback. [DATA_SOURCES.md](DATA_SOURCES.md#zoom). Report: [Development/LAVR_PHASE_5B_REPORT.md](Development/LAVR_PHASE_5B_REPORT.md).

### Target Zoom flow (Phase 5B)

```text
Zoom Meeting ends
    ↓
Zoom processes cloud transcript
    ↓
recording.transcript_completed webhook
    ↓
LAVR webhook endpoint
    ↓
validate Zoom webhook
    ↓
persist import event / deduplicate
    ↓
queue transcript import
    ↓
Zoom API
GET /meetings/{meetingId}/transcript
    ↓
download original transcript (backend, OAuth token)
    ↓
Meeting
    ↓
Meeting Intelligence
    ├── Participants
    ├── Summary
    ├── Topics
    ├── Decisions
    ├── Tasks
    ├── Commitments
    ├── Deadlines
    ├── Open Questions
    ├── Risks
    └── Leadership Review
    ↓
Operational Core
    ↓
Automation Engine
```

The webhook handler must **not** run AI, download a large transcript synchronously, or run Meeting Intelligence inline. It validates the event, deduplicates, enqueues a short import job, and returns **HTTP 200 or 204** immediately. Heavy work is queue-only.

Exact Zoom app type chosen for this dedicated backend: **Server-to-Server OAuth** (`grant_type=account_credentials`). Current granular scopes: `cloud_recording:read:meeting_transcript:admin`, `cloud_recording:read:list_recording_files:admin`, `user:read:user:admin`. No write/delete scopes. Credentials live in `integration_accounts.credentials_encrypted`. Webhook: `POST /webhooks/zoom`. Event: `recording.transcript_completed`. Transcript: `GET /meetings/{meetingId}/transcript` then download `download_url` with Bearer token from trusted Zoom hosts only.

Current Zoom REST surface to use at implementation time: `GET /meetings/{meetingId}/transcript` (transcript information + `download_url`). Download with the Zoom OAuth access token on the backend. Do not expose Zoom tokens or durable public download URLs to the frontend.

### Source artifact

Keep:

- original transcript (normalized copy plus provider bytes/format as stored);
- provider metadata;
- source identifiers;
- meeting timestamps;
- host;
- download / import timestamp.

Do **not** keep only an AI summary.

### Transcript normalization

Phase 5A parsers must not assume manual TXT only.

```text
Zoom VTT
TXT
future other transcript formats
        ↓
Canonical Transcript
        ↓
Meeting Intelligence
```

LAVR Meeting Intelligence is the canonical analysis layer. Optional future Zoom artifacts (recording metadata, in-meeting chat, Zoom-generated summaries/next steps when the account/API exposes them) may be ingested as extras. Do not depend on Zoom AI summary being present.

### Idempotency

Zoom may redeliver webhooks. Do not create duplicate Meetings.

External identity (exact schema in Phase 5):

```text
provider = zoom
zoom_account_id
zoom_meeting_id
zoom_meeting_uuid
zoom_recording / transcript identifier
```

Unique / deduplication strategy is mandatory.

### Import status

| Status | Meaning |
| --- | --- |
| `pending` | Accepted; not downloaded yet |
| `downloading` | Fetching transcript |
| `processing` | Canonical transcript + analysis |
| `completed` | Meeting + derived facts stored |
| `failed` | Terminal failure after bounded retries |
| `blocked_auth` | OAuth expired / insufficient permission — diagnosable |
| `transcript_unavailable` | Transcript not ready yet — **not** a permanent failure |

No infinite aggressive retries. If the transcript is not ready, wait or retry with backoff; do not mark the meeting failed forever.

### Security (Phase 5B)

- Validate webhook authenticity per current Zoom webhook protocol.
- Encrypt secrets. Access tokens backend-only.
- Do not log raw Zoom tokens or full transcript bodies in technical logs.
- Do not publish transcript `download_url` as a lasting public URL.
- Rate-limit the webhook; replay protection in a reasonable form.
- Persist raw webhook payloads only if needed for audit/debug.

### Project binding

Bind a Zoom meeting to a Project when signals are strong enough: calendar event, host/account, topic, participants, mailbox/business context, previous mapping.

If confidence is insufficient: create the Meeting, leave project **unresolved**, wait for AI/admin confirmation. Do not guess silently.

### People identity

Map Zoom participants onto future `people` using email, Zoom identity, display name, existing aliases, and confidence.

Do **not** auto-create a Person for every name string. Unresolved participants are allowed.

### Commitments

Phase 6 **IMPLEMENTED.** High-confidence `commitments_detected` become first-class `detected` rows (source type `meeting`). Medium/low stay suggestions until Owner promote. Re-analysis is idempotent by fingerprint. Zoom-sourced meetings use the same path as manual meetings. Operational commitments are not deleted when a later analysis omits them. [COMMITMENTS.md](COMMITMENTS.md).

### Leadership Review

Optional analysis of **execution quality** of the meeting (clarity of owners/deadlines/decisions). Not psychology. Foundation in Phase 5A; product slice Phase 9. [LEADERSHIP_REVIEW.md](LEADERSHIP_REVIEW.md).
