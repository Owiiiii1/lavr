# Executive Brief

Canonical briefing product. Automation/validation: [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md). Domain: [DOMAIN_MODEL.md](DOMAIN_MODEL.md). Report: [Development/LAVR_PHASE_8_REPORT.md](Development/LAVR_PHASE_8_REPORT.md).

The Daily Executive Brief is a **central scenario**. Principle: **reduction to attention**, not volume.

---

## CURRENT (Phase 8)

First-class `executive_briefs` (2026-09-10). Reuses the Phase 7 pipeline (COLLECT → NORMALIZE → PRIORITIZE → VALIDATE → optional AI → RENDER → DELIVER → RECORD). Not a second report engine. Not a `ScheduledReportType`.

| Piece | Status |
| --- | --- |
| `executive_briefs` table + morning/evening/weekly types | **IMPLEMENTED** (UI is morning-first) |
| Owner settings: enabled, local time, Telegram, in-app, weekends | **IMPLEMENTED** (timezone/locale = Owner profile; default recommendation 08:30, weekends off) |
| Collectors: commitments, meetings + analysis, calendar, Gmail, blocked integrations, repeat automation failures, optional Leadership signal | **IMPLEMENTED** (Gmail/Calendar **multi-account**; one blocked mailbox → partial) |
| Deterministic priority (`critical`/`high`/`normal`/`low`) + Attention Now | **IMPLEMENTED** |
| Dedupe keys + previous-brief delta | **IMPLEMENTED** |
| AI phrasing optional; invalid/truncated/JSON/unknown source → deterministic fallback | **IMPLEMENTED** |
| Partial sources (Gmail/Calendar unavailable) | **IMPLEMENTED** (UNKNOWN ≠ EMPTY; stories are aggregated, not 10 mailbox alerts) |
| Today `/lavr/today` uses latest morning brief as the layer | **IMPLEMENTED** |
| Workspace `/lavr/briefs` + detail + Generate now | **IMPLEMENTED** |
| Telegram one compact message + `brief_{id}` WebApp deep link | **IMPLEMENTED** |
| Idempotent scheduled key `executive_brief:{user_id}:morning:{local-date}` | **IMPLEMENTED** |
| Admin `/executive-briefs` (metrics, regenerate, no bodies) | **IMPLEMENTED** |
| Owner live synthetic morning scenario | **NOT VALIDATED** |

Productivity Daily Brief is skipped when morning Executive Brief is enabled.

Empty sections are omitted. Empty morning: short “no critical issues” copy, not a dump.

Decisions are aggregated as brief items from Meeting Intelligence / detected commitments / blocked automations. No first-class `decisions` table.

LAVR does not write third parties. Follow-ups are suggestions (“Нагадати?”).

---

## Pipeline

COLLECT → NORMALIZE → PRIORITIZE → VALIDATE → OPTIONAL AI SYNTHESIS → RENDER → DELIVER → RECORD.

AI may compact wording. AI does not decide deadlines, overdue, source failure, schedule, or delivery idempotency.

---

## Delivery

| Channel | Rendering |
| --- | --- |
| Telegram Chat | One compact message; truncate to Attention + Open in LAVR if too long |
| Web / WebApp | `/lavr/today` card + `/lavr/briefs/{id}` |
| In-app inbox | Only when Telegram was not sent |

Scheduled morning is skipped on weekends unless `morning_brief_weekends`. Manual generate always works.

---

## TARGET (later)

Evening/weekly product surfaces, first-class Decisions, PDF export — not Phase 8. Leadership Review is Phase 9 (**IMPLEMENTED**); Brief may include one actionable process signal only.
