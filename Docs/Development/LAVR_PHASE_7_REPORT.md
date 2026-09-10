# LAVR Phase 7 report — Automation Engine hardening

**Date:** 2026-09-10  
**Repo:** `Owiiiii1/lavr` (`main`)  
**Host path:** `/var/www/lavr`  
**Public URL:** https://lavr.youngfashionshow.com  

No secrets, email bodies, transcripts, private notes, or report bodies are recorded here.

---

## Status

| Item | Status |
| --- | --- |
| Automation execution model (COLLECT → VALIDATE → RENDER → DELIVER → RECORD) | **IMPLEMENTED** |
| `automation_runs` schema + unique `run_key` | **IMPLEMENTED** |
| Routing Reminder / Watcher / Scheduled Report / Commitment / Proactive | **IMPLEMENTED** |
| Idempotency (slot / poll / transition keys + lock) | **IMPLEMENTED** |
| Bounded retries (429 / timeout retryable; auth not) | **IMPLEMENTED** |
| Partial success (Gmail ok + Calendar fail) | **IMPLEMENTED** |
| AI synthesis optional + `ReportOutputValidator` fallback | **IMPLEMENTED** |
| Watcher transition / no-repeat (cursor fingerprint) | **IMPLEMENTED** |
| Scheduled report deterministic sections | **IMPLEMENTED** |
| Commitment transitions on `automation_runs` + one due/overdue notify | **IMPLEMENTED** |
| External action policy (third-party default suggest/draft) | **IMPLEMENTED** |
| Delivery abstraction (in-app + Owner Telegram) | **IMPLEMENTED** |
| Health states (healthy / degraded / blocked / disabled) | **IMPLEMENTED** |
| Stale processing recovery `automation:recover-stale-runs` | **IMPLEMENTED** |
| Admin Automation Runs + retry | **IMPLEMENTED** |
| Workspace health / last result | **IMPLEMENTED** |
| Tests (routing, idempotency, partial, validation, stale, admin) | **IMPLEMENTED** |
| Owner live synthetic A–E | **NOT VALIDATED** |
| Executive Brief redesign / Leadership Review / rule DSL | **NOT** (out of Phase 7) |

---

## Automation execution model

AI may classify intent and phrase content. Code owns schedule, routing, retries, validation, delivery, and idempotency.

Pipeline: COLLECT → NORMALIZE → ANALYZE (optional) → VALIDATE → RENDER → DELIVER → RECORD RESULT.

---

## `automation_runs`

Additive table. Unique `(user_id, run_key)`. Metrics are counts and `prompt_version` only.

Statuses: `processing`, `success`, `skipped`, `no_change`, `partial`, `failed`, `retryable`.

---

## Routing contract

| Kind | Meaning |
| --- | --- |
| Reminder | Time-based notify |
| Watcher | Condition; fire on change |
| Scheduled Report | Periodic composed digest |
| Commitment | Deterministic lifecycle |
| Proactive suggestion | Suggestion, no external write |

Ambiguous periodic mail that is both digest and a specific condition: **ask once**. Periodic mail without a condition is a Scheduled Report, never a Gmail digest watcher.

---

## Idempotency / locking / retries

Deterministic `run_key`. Cache lock around execution. Unique scheduled-report `(id, slot_key)`. Watcher jobs unique until processing.

Retryable: network timeout, 429, transient 5xx. Non-retryable: invalid config, revoked auth. Auth → `blocked`, not endless retry.

---

## Partial success / validation / fallback

Optional sources swallow errors. Report still delivers with “Календарь сейчас недоступен” (or Gmail equivalent). Empty AI / JSON / technical markers / subject dump / truncated → deterministic fallback. AI disabled still delivers.

---

## Watcher / reminder / report / commitment

Watcher state is cursor + fingerprints, not LLM memory. Reminder trigger is DB `run_at`. Recurrence computes the next occurrence. Reports have schedule, timezone, sources, delivery, locale, enabled, last/next run. Commitment `due_soon` / `overdue` notify once; overdue copy may ask «Нагадати?» without writing to the person.

---

## Delivery / policy / health / stale

In-app inbox is required for critical results. Owner Telegram is additional. Delivery key = `run_key`. `ExternalActionPolicy` forbids silent third-party writes.

Health badges on Workspace cards. Admin `/automation-runs` filters by type/status/date/id/failed; Retry keeps the same `run_key`.

Stale processing older than `automation.stale_processing_minutes` (default 30) → `retryable` / `failed_stale`.

---

## Tests

- Intent router: reminder / watcher / report / ambiguous
- No Gmail digest watcher from tools; “жди письмо” is a Gmail event watcher
- Report same slot once; commitment overdue once; reminder telegram retry reuses `run_key` without duplicate delivery
- AI JSON rejected → fallback; Calendar fail → partial
- Watcher `still_open` + hours delay; same-minute no_change does not block a later match
- Stale recovery
- Admin Owner-only

---

## Manual validation

| Scenario | Status |
| --- | --- |
| A daily report Gmail + Calendar + Commitments, AI disabled | **NOT VALIDATED** |
| B watcher first match notify, next poll no duplicate | **NOT VALIDATED** |
| C overdue commitment one notification | **IMPLEMENTED** in tests; Owner live **NOT VALIDATED** |
| D temporary source timeout → partial | **IMPLEMENTED** in tests; Owner live **NOT VALIDATED** |
| E failed job retry → one delivery | **NOT VALIDATED** |

---

## Production deploy

Additive migration `automation_runs`. Artisan `/usr/bin/php8.5`. Frontend build. No nginx / Telegram webhook / Zoom webhook changes.

---

## Known limitations

- Full operational event bus is still TARGET (Phase 11).
- Report deterministic copy remains largely Russian to match existing tests; Owner UI locales uk/en/ru elsewhere.
- Digest watcher evaluation path still exists for legacy rows; create-path will not create new ones from periodic-digest phrases.
- Executive Brief / Leadership Review not in this phase.

## Phase 8 readiness

Scheduled report machinery and `automation_runs` are stable enough to hang an Executive Brief type on the same pipeline. Do not invent a second engine.
