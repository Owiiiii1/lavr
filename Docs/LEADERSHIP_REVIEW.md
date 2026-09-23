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

## Three layers (do not merge them)

| Layer | Status | What it is |
| --- | --- | --- |
| A. Weekly process review | **CURRENT** (`leadership_reviews`) | Period metrics and findings. Not a person score |
| B. Per-meeting selected participant | **CURRENT** (review v2 on the meeting analysis) | One transcript, one `review_subject_person_id`. Indicators only |
| C. CEO longitudinal development | **TARGET** | Pattern over time. Not a subsystem |

No HRM. No disciplinary or salary use. No employee leaderboard.

---

## Per-meeting review subject

Separate from the weekly `leadership_reviews` product.

`user_productivity_settings.default_review_person_id` is the person used for automatic Zoom and manual imports. `auto_generate_leadership_review` can turn that off. Each meeting can override `review_subject_person_id`.

If that person is not an exact participant match, meeting facts still complete and `leadership_review_status` is `pending_subject`. No name guessing. No overall score. Findings only use actions tied to the selected person by owner name or speaker. Indicators are `good`, `needs_attention`, or `insufficient_data`.

The weekly Leadership Review collector is unchanged.

---

## TARGET — CEO longitudinal development

Conceptual only. No schema.

A pattern would carry: pattern key, first observed, last observed, evidence count, whether it is repeated, improving, or resolved, a trend note, related meetings, and related commitments.

Sequence: isolated → repeated → improving → resolved.

Coaching categories (TARGET names, not stored keys):

- `follow_up_questions`
- `solution_jumping`
- `task_clarity`
- `owner_clarity`
- `deadline_clarity`
- `metric_clarity`
- `definition_of_done`
- `decision_closure`
- `delegation`
- `priority_discipline`
- `follow_up_discipline`
- `owner_dependency`
- `repeated_miss_handling`
- `data_discipline`
- `meeting_closure`

Review v2 already speaks about task, owner, deadline, decision closure, follow-up, and delegation for **one meeting**, as `good` / `needs_attention` / `insufficient_data`. That is not this longitudinal list and not a score.

### Score — PRODUCT DECISION REQUIRED

A client note asked for a CEO score out of 10 and a manager score out of 10.

**CURRENT rule stands:** no magic composite leadership score. No 73/100. No 8.2/10 overall. No employee ranking.

**CANDIDATE, not approved:** a component scorecard for meeting coaching, for example Questions 4/10, Deadline clarity 3/10, Delegation 8/10.

If that candidate is ever built, every component needs a written method and evidence. An unexplained overall number is still forbidden. Do not implement from this paragraph.
