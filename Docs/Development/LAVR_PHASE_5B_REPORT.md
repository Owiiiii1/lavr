# LAVR Phase 5B report — Zoom cloud transcript ingest

**Date:** 2026-09-10  
**Repo:** `Owiiiii1/lavr` (`main`)  
**Host path:** `/var/www/lavr`  
**Public URL:** https://lavr.youngfashionshow.com  

No secrets, tokens, webhook secrets, transcript bodies, participant emails, or private notes are recorded here.

---

## Status

| Item | Status |
| --- | --- |
| Zoom integration config + encrypted credentials | **IMPLEMENTED** |
| Server-to-Server OAuth token fetch/cache | **IMPLEMENTED** (mocked tests) |
| Webhook CRC + signature + replay + account allowlist | **IMPLEMENTED** |
| `recording.transcript_completed` async ingest | **IMPLEMENTED** |
| Meeting UUID dedupe + artifact checksum | **IMPLEMENTED** |
| Existing transcript normalizer + `AnalyzeMeetingTranscriptJob` | **IMPLEMENTED** (reused) |
| Settings → Integrations Zoom card + Test Connection | **IMPLEMENTED** |
| Owner/Admin source + retry UI (uk/en/ru) | **IMPLEMENTED** |
| Mocked automated tests | **IMPLEMENTED** |
| LIVE Zoom E2E | **NOT VALIDATED** |
| Historical bulk import | **NOT** (architecture does not block a later admin action) |

---

## Chosen Zoom surface (current official docs)

| Topic | Value |
| --- | --- |
| App type | **Server-to-Server OAuth** (dedicated backend for one Zoom account; no Owner interactive login) |
| OAuth grant | `account_credentials` → `POST https://zoom.us/oauth/token` |
| Token | ~3600s, no refresh token; cache until expiry minus skew; re-request |
| Scopes | `cloud_recording:read:meeting_transcript:admin`, `cloud_recording:read:list_recording_files:admin`, `user:read:user:admin` |
| Webhook URL | `https://lavr.youngfashionshow.com/webhooks/zoom` (`POST /webhooks/zoom`) |
| CRC | `event=endpoint.url_validation`; `encryptedToken` = hex HMAC-SHA256(webhook secret, `plainToken`) |
| Signature | `x-zm-signature` = `v0=` + HMAC-SHA256(secret, `v0:{timestamp}:{rawBody}`); replay window 300s; `hash_equals` |
| Canonical event | `recording.transcript_completed` (`recording.completed` stored as telemetry only) |
| Transcript API | `GET /meetings/{meetingId}/transcript` (UUID double-encoded) |
| Download | Bearer OAuth token; HTTPS; allowlisted Zoom hosts; max size; no arbitrary SSRF |

User-managed OAuth was **not** required for this dedicated single-account backend. If a later client Zoom setup cannot use S2S, that must be re-evaluated.

---

## Architecture

```text
Zoom recording.transcript_completed
  → POST /webhooks/zoom (validate, dedupe, persist subset, 204)
  → ProcessZoomTranscriptJob (default queue)
      → S2S token (cache)
      → GET /meetings/{uuid}/transcript
      → download from trusted host
      → Meeting source_type=zoom / source_external_id=UUID
      → MeetingArtifact (original + Phase 5A normalizer)
      → AnalyzeMeetingTranscriptJob (existing analysis queue)
```

No Zoom-specific analysis pipeline, commitment extractor, or summary service.

Idempotency: unique `zoom_webhook_events.external_event_key` (`event|event_ts|account_id|uuid|recording_file_id`). Duplicate webhook returns 204 and does not re-import.

Meeting uniqueness: unique `(user_id, source_type, source_external_id)`. Recurring meetings share numeric id; UUID distinguishes instances.

Project binding: never auto-set. Existing `project_id`, `organization_id`, `notes`, and linked participants are preserved on redelivery.

Participants: host email/name via existing `ParticipantResolver`. People are not created automatically.

---

## Error states

Stored on `meetings.metadata.zoom.import_status`:

- `pending` / `downloading` / `processing` / `completed`
- `failed` / `blocked_auth` / `transcript_unavailable`

401/invalid credentials stop retry and mark the integration error as `blocked_auth`. Transcript 404/NOT_READY is retryable. 429 honors `Retry-After`.

---

## Owner/Admin UI

- Settings → Integrations: Zoom card (connected/not, enabled, account id, webhook URL, last event/import/error, Test Connection). Secrets never rendered.
- Admin meeting detail: Source Zoom + import status + safe error + Retry.
- Workspace meeting card: Zoom source label + processing/failed human string (uk/en/ru). Failed import is short, not technical.
- After successful Zoom analysis: LAVR notification «Зустріч проаналізовано» / Meeting analyzed / Встреча проанализирована with Open `/lavr/meetings/{id}`. Deep link `meeting_{id}` is allowlisted.

Telegram outbound notification was not added (not a Phase 5B blocker).

---

## Manual setup (Owner / Admin)

1. In Zoom Marketplace create a **Server-to-Server OAuth** app for the client account.
2. Add only the scopes listed above. Enable Event Subscriptions: `recording.transcript_completed`. Endpoint URL = `https://lavr.youngfashionshow.com/webhooks/zoom`. Copy the webhook secret token.
3. Enable cloud recording and audio transcript generation on the Zoom account/meetings that should import. LAVR cannot invent a transcript Zoom did not generate.
4. In LAVR Admin → Settings → Integrations → Zoom, paste Account ID, Client ID, Client Secret, webhook secret. Enable. Test Connection. Do not put secrets in `.env` unless this DB path is later replaced.
5. Complete a short Zoom meeting with cloud recording/transcript, end it, wait for transcript completed. Confirm Meeting, artifact, and analysis. Redeliver the webhook once: no duplicate Meeting.

---

## Tests

Feature tests (HTTP mocked, no live Zoom):

- CRC challenge, valid/invalid signature, expired timestamp, wrong account, duplicate webhook, no sync API/AI, job dispatch
- Token fetch, cache, refresh, `blocked_auth`, token never logged
- Transcript metadata, Bearer download, trusted/untrusted host, timeout, 404 retry, 429 Retry-After, checksum, UUID vs recurring numeric id, private storage, existing analyzer dispatched
- Settings secrets omitted from HTML; Test Connection does not create a Meeting
- Phase 5A manual paste still works; deep link `/lavr/meetings/{id}`

---

## Production deploy

Additive migrations only. Artisan: `/usr/bin/php8.5 artisan migrate --force`, `route:cache`, frontend build. nginx, Telegram webhook, and Telegram token untouched. Queue workers already drain `analysis,memory,default`.

---

## Known limitations / Phase 6 readiness

- LIVE Zoom E2E is **NOT VALIDATED**.
- No historical bulk import.
- No Zoom meeting bot, audio transcription, or Zoom AI Companion replacement.
- Commitments/decisions remain Meeting Intelligence JSON, not first-class rows.
- Automatic project binding is deferred (Phase 10 multi-source bindings).
- Transcript import depends on Zoom cloud recording + transcript being enabled for that account/meeting.

Phase 6 can treat Zoom-sourced Meetings the same as manual meetings for commitment extraction.

---

## Definition of Done

Phase 5B code path is complete in this repository. Live Zoom credentials were not present; do not claim the integration works against a real Zoom account until the manual live checklist in this report is executed.
