# LAVR Phase 5A — Meeting Intelligence and transcript import

**Date:** 2026-09-09  
**Branch:** `main`  
**Remote:** `Owiiiii1/lavr` only  
**Public URL:** https://lavr.youngfashionshow.com  
**Commit intent:** `feat: add meeting intelligence and transcript import`

Status vocabulary: [CURRENT_STATE.md](../CURRENT_STATE.md).

No secrets, tokens, webhook secrets, transcript bodies, participant emails, or private notes are recorded here.

---

## Result

Phase 5A adds a first-class **Meeting** domain. A Meeting is the canonical operational entity. Calendar events, Knowledge events, and transcript files are not Meetings.

Owner/Admin can create a Meeting from a **file upload** or **pasted text**. The original artifact is stored on the private `local` disk. Analysis runs on the `analysis` queue and writes versioned **Meeting Intelligence** JSON. Detected commitments and decisions stay in that JSON. Phase 6 first-class `commitments` rows are not created. Zoom OAuth/webhooks and audio/video transcription are not in this phase.

---

## Schema

| Table | Role |
| --- | --- |
| `meetings` | Canonical meeting (`user_id`, optional `project_id` / `organization_id`, title, times, `source_type`, split `status` / `analysis_status`, `current_analysis_id`, summary, notes, metadata) |
| `meeting_participants` | Resolved `person_id` **or** unresolved display name / email / speaker key |
| `meeting_artifacts` | Original file or pasted text + normalized text, checksum, private storage path |
| `meeting_analyses` | Versioned analysis history (`result_json`, provider/model, prompt_version, safe error) |

Migration: `database/migrations/2026_09_09_160000_create_meetings_tables.php` (additive, reversible).

**Meeting status:** `draft` | `ready` | `archived`  
**Analysis status:** `pending` | `processing` | `completed` | `failed`  

These are separate so a failed AI run does not mean the meeting did not happen.

**Source type:** `manual_upload` | `manual_text` | `calendar_link` | `zoom` (`zoom` reserved only).

---

## Storage

**IMPLEMENTED.** Same private disk as Storage (`storage/app/private`), directory `meetings/{user_id}/{meeting_id}/{uuid}.{ext}`. Not under `/public`. Authenticated Owner/Admin download only (`Cache-Control: private`). Size cap 8 MB. Extensions: `txt`, `vtt`, `srt`, `md`. Executable/`<?` content rejected. Client filename is never used as the storage path.

---

## Formats / normalization

**IMPLEMENTED.** `.txt` / `.md` keep speaker labels and `[timestamp]` prefixes. `.vtt` / `.srt` parse cues into `[HH:MM:SS] Speaker: text`. Original bytes/text are not rewritten. Normalized text is a separate field on the artifact. No OCR. No audio/video pipeline.

---

## Participant resolution

**IMPLEMENTED.** Seed from speaker labels. Resolve to an existing Person by email identity or unique exact `normalized_name`. Never auto-create a Person. Unresolved participants stay on the meeting. Owner/Admin can link, unlink, or explicitly **Create Person from participant**.

---

## Analysis pipeline

**IMPLEMENTED.** COLLECT → NORMALIZE → ANALYZE (queue) → VALIDATE → STORE → RENDER.

Chunking: speaker/line-aware, ~6000 chars, overlap, max 12 chunks, per-chunk extract, deterministic merge/dedupe, final schema validation. Invalid JSON: bounded per-chunk retries then `analysis_status=failed`. Crooked output is never stored as completed.

Prompt forbids invented names, projects, owners, deadlines, and completion status. Relative deadlines become absolute only when `started_at` is known; otherwise `deadline_raw` is kept and `deadline_at` is null.

Evidence snippets (short excerpt, optional speaker/timestamp/offsets) on decisions, action items, detected commitments, and deadlines.

---

## JSON schema (analysis)

Stored in `meeting_analyses.result_json`:

- `summary` (`executive`, `outcomes` 3–7, `attention`)
- `participants`, `topics`
- `decisions`, `action_items` (status `detected`), `commitments_detected`, `deadlines`
- `open_questions`, `risks`, `follow_ups`, `unresolved_identities`
- `likely_project` (suggestion only — never silent `project_id` write)
- evidence objects on operational items
- confidence `high` | `medium` | `low`

`commitments_detected` ≠ Phase 6 `commitments` table.

---

## Retry / queue

**IMPLEMENTED.** `AnalyzeMeetingTranscriptJob` on queue `analysis` (scheduler already runs `queue:work … --queue=analysis,memory,default`). Tries from `reliability.job_tries` (3), backoff `[30,90,180]`, unique until processing per meeting. Logs: meeting_id, analysis_id, provider/model, attempt, outcome, checksum/counts. Does **not** log transcript body, emails, notes, or long excerpts. Manual re-run creates a new analysis version without re-uploading.

---

## UI

**IMPLEMENTED.**

- Workspace: `/lavr/meetings`, `/lavr/meetings/{meeting}` — list filters (project/date/status/search), import file or paste, detail sections, collapsed searchable transcript, simple `router.reload` poll while processing, participant linking. Locales `uk` / `en` / `ru` (Ukrainian primary).
- Admin: `/meetings` technical CRUD, artifact download, re-run, participant link/unlink/create person.

AI does not auto-assign `project_id` or `organization_id`.

---

## AI tools

**IMPLEMENTED** (read only): `list_meetings`, `find_meeting`, `get_meeting`, `get_meeting_analysis`. Chat answers use stored analysis. Tools do not dump the full transcript.

---

## Tests

**IMPLEMENTED.** Feature coverage: models/relations, VTT/SRT normalize, chunking, validator relative deadlines, merger dedupe, paste, txt upload, unsupported file, duplicate checksum on same meeting, auth (guest redirect, foreign 404, regular 403), Workspace list/detail, participant link/unlink, re-run dispatch, invalid AI → failed, successful analysis storage + evidence, no `commitments` table, four read tools, registry, Telegram `/lavr/meetings` page.

Synthetic fixture: `tests/Fixtures/meetings/synthetic.vtt` (not a production client transcript).

---

## Manual checks

| Check | Status |
| --- | --- |
| Synthetic 3 participants / 2 decisions / 3 actions / 2 deadlines / 1 unresolved / 1 risk / 1 open question via Fake AI in tests | **IMPLEMENTED** |
| Owner UI in a real browser with live analysis AI | **NOT VALIDATED** |
| Production queue worker picking `analysis` jobs after deploy | **NOT VALIDATED** (worker already includes `analysis` queue) |

---

## Known limitations

- No Zoom ingest (Phase 5B).
- No audio/video transcription.
- No first-class commitments/decisions/follow-up automation.
- No semantic global search.
- Likely project is suggestion-only.
- Calendar association is a nullable `source_external_id` only.
- Chunk merge is deterministic, not a second LLM reducer (except each chunk is LLM).
- Admin copy remains English-first.

---

## Production deploy

| Step | Status |
| --- | --- |
| `php8.5 artisan migrate:status` then additive migrate | **IMPLEMENTED** (run on deploy of this commit) |
| nginx / Telegram bot / webhook | unchanged |
| Frontend Vite build | required on deploy (`public/build` gitignored) |
| `config:cache` | not required unless already used on host |

---

## Phase 5B readiness

Meetings, artifacts, `source_type=zoom`, and `source_external_id` exist. Phase 5B can attach Zoom OAuth + `recording.transcript_completed` → download → reuse the same Meeting + Intelligence pipeline. Do not start Zoom in 5A.

---

## Status summary

| Area | Status |
| --- | --- |
| Schema / storage / formats / normalization | **IMPLEMENTED** |
| Participant resolution | **IMPLEMENTED** |
| Pipeline / chunking / JSON schema / evidence / retry / queue | **IMPLEMENTED** |
| Workspace + Admin UI + uk/en/ru | **IMPLEMENTED** |
| AI read tools | **IMPLEMENTED** |
| Automated tests | **IMPLEMENTED** |
| Live Owner transcript in production | **NOT VALIDATED** |
| Zoom / audio / first-class commitments | **TARGET** (out of 5A) |
