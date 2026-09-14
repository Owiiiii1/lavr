# Leadership Review

Canonical **process-quality** analysis of management rituals: meetings, commitments, ownership, deadlines, follow-up. Meetings: [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md). Commitments: [COMMITMENTS.md](COMMITMENTS.md). Report: [Development/LAVR_PHASE_9_REPORT.md](Development/LAVR_PHASE_9_REPORT.md).

Leadership Review analyses **PROCESS, not PERSONALITY**.

---

## CURRENT (Phase 9)

First-class `leadership_reviews` (2026-09-14). Pipeline: COLLECT → METRICS → FINDINGS → VALIDATE → optional AI wording → STORE → RENDER. Deterministic baseline works with AI off.

| Piece | Status |
| --- | --- |
| `leadership_reviews` + review types `owner` / `project` / `meeting` / `team` / `person` | **IMPLEMENTED** |
| Periods: 7 days, 30 days (default), custom range | **IMPLEMENTED** |
| Deterministic metrics + ratios (no overall leadership score) | **IMPLEMENTED** |
| Grounded findings + evidence refs | **IMPLEMENTED** |
| Strengths + attention areas + previous-period trend | **IMPLEMENTED** |
| Minimum sample guard (`< 3` → insufficient trend, low confidence) | **IMPLEMENTED** |
| Sensitive wording guard (uk/en/ru personality language rejected) | **IMPLEMENTED** |
| Optional AI compact wording; hallucination / personality → fallback | **IMPLEMENTED** |
| Workspace `/lavr/leadership` + generate (overall / project / person / meeting) | **IMPLEMENTED** |
| Project «Leadership / Process», Person «Operational patterns», Meeting «Meeting quality» | **IMPLEMENTED** |
| Weekly scheduler + Telegram compact + `leadership_{id}` deep link | **IMPLEMENTED** |
| Executive Brief may include one actionable `leadership_signal` | **IMPLEMENTED** |
| Admin `/leadership-reviews` (counts, regenerate, no transcripts) | **IMPLEMENTED** |
| Owner live synthetic dataset | **NOT VALIDATED** |

Owner timezone is canonical for presentation and the weekly schedule.

Phase 11 does not add notification-volume metrics. Repeated overdue / unresolved ownership still come from commitments and meeting follow-up gaps. Proposal history is available for a later collector if needed. [PROACTIVE_OPERATIONAL_CONTROL.md](PROACTIVE_OPERATIONAL_CONTROL.md).

Regeneration always creates a **new** historical row. Scheduled key: `leadership_review:{user_id}:weekly:{period_start}`. Manual keys append `:manual:{suffix}`.

Gmail/Calendar absence does not fail the review. Meetings + commitments are enough. Status `partial` if a structured source fails; `insufficient_data` if both meetings and commitments are empty. Email/message **volume is not a productivity metric**. Cross-source evidence may inform follow-up gaps only.

### Allowed / forbidden

Allowed: unclear ownership, missing deadline, repeated overdue, unresolved meeting actions, decisions without follow-through, Owner concentration, workload concentration, reopened topics, follow-up gaps, low action clarity.

Forbidden: lazy / toxic / unreliable person, personality typing, emotional diagnosis, employee ranking, leadership score 73/100, online-time or sentiment as performance.

Person review is **operational patterns around this person**, not a performance score.

### Metrics (deterministic)

`commitments_total` / `open` / `overdue` / `confirmed` / `without_deadline` / `without_person`, `meeting_action_items`, `meeting_actions_without_owner` / `without_deadline`, `decision_like_items`, `reopened_topics`, `likely_done_unconfirmed`, `detected_unreviewed`, `projects_with_blockers`, `automation_blocked_count`.

Ratios: `owner_coverage_pct`, `deadline_coverage_pct`, `commitment_confirmation_pct`, `overdue_ratio`, `followup_gap_ratio`. No magic overall score.

Trend language: «Покриття дедлайнами покращилось з 62% до 81%.» Not «стиль управления улучшился на 19%.»

### AI

Wording and grouping only. Must not invent findings, score people, or assert unsupported causality. Validator rejects unknown persons, personality adjectives, invented numbers, wrong locale, raw JSON. Fallback is production-quality deterministic uk/en/ru.

### Privacy

Logs: `review_id`, period, counts, duration, status. Not transcripts, emails, or recommendation bodies.

---

## TARGET (later)

First-class Decisions table. Multi-source Phase 10. No HRM, no disciplinary or salary recommendations.
