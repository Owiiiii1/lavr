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
| Collectors: commitments, meetings + analysis, calendar, Gmail, blocked integrations, repeat automation failures, optional Leadership signal, unresolved high/critical proactive items (non-commitment, to avoid duplicate stories) | **IMPLEMENTED** (Gmail/Calendar **multi-account**; one blocked mailbox → partial) |
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

Owner timezone (`users.timezone`) is canonical for presentation and scheduling. Live morning scenario remains **NOT VALIDATED**.

Empty sections are omitted. Empty morning: short “no critical issues” copy, not a dump.

Decisions are aggregated as brief items from Meeting Intelligence / detected commitments / blocked automations. No first-class `decisions` table.

LAVR does not write third parties. Follow-ups are suggestions (“Нагадати?”). Morning Brief reads current unresolved high/critical proactive items without duplicating commitment overdue/likely_done/detected stories already collected as commitments. [PROACTIVE_OPERATIONAL_CONTROL.md](PROACTIVE_OPERATIONAL_CONTROL.md).

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

The `executive_briefs` type column already allows morning, evening, and weekly. **CURRENT product surface is the morning brief.** Evening and weekly expansions below are **TARGET** until a UI and collector actually ship them. First-class Decisions and PDF export stay later. Leadership Review is Phase 9 (**IMPLEMENTED**); the morning brief may include one actionable process signal only.

### Morning (mostly CURRENT)

Attention Now, overdue commitments, calendar, blocked integrations, and a leadership signal already exist. **TARGET additions:** today’s outcomes, decisions needed, “waiting on CEO”, and a clearer key-calendar line. Do not describe those additions as collected today.

### Evening (TARGET)

Concrete results today, commitments completed, commitments missed, unresolved items, tomorrow’s plan, waiting on CEO, repeated misses, one material after-hours note. Not a volume dump.

### Weekly (TARGET)

3–5 company outcomes and their progress, manager commitments, decisions, repeated misses, CEO patterns, key risks, next week’s priorities. CEO patterns are not a longitudinal store yet. See [CEO_OPERATING_SYSTEM.md](CEO_OPERATING_SYSTEM.md).
