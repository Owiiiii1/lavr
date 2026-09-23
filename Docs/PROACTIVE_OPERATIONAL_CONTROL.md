# Proactive Operational Control

Canonical Phase 11 control loop. Events: [EVENT_MODEL.md](EVENT_MODEL.md). Automation: [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md). Commitments: [COMMITMENTS.md](COMMITMENTS.md). Report: [Development/LAVR_PHASE_11_REPORT.md](Development/LAVR_PHASE_11_REPORT.md).

LAVR observes meaningful operational facts, correlates them to People / Projects / Commitments / Meetings, assesses whether the Owner should act, and proposes a concrete next step.

**Default: no third-party write** without policy or explicit Owner approval.

Pipeline:

```text
OBSERVE → CORRELATE → ASSESS → PROPOSE → APPROVE/POLICY → EXECUTE optional → VERIFY → RECORD
```

AI may correlate, summarize, and phrase. Code/policy owns notify / allow / dedupe / retry / cooldown / execute.

## CURRENT (Phase 11)

| Piece | Status |
| --- | --- |
| First-class `operational_events` | **IMPLEMENTED** |
| First-class `proactive_proposals` + audits | **IMPLEMENTED** |
| Typed `OperationalRuleRegistry` (no Zapier DSL) | **IMPLEMENTED** |
| `OperationalAssessmentService` (importance, urgency, actionability, notify) | **IMPLEMENTED** |
| Fingerprint dedupe (same fact = one event; transition = new fingerprint) | **IMPLEMENTED** |
| Severity `critical` / `high` / `normal` / `low` with overdue escalation 6h / 24h / 72h | **IMPLEMENTED** |
| Quiet hours, daily cap, cooldown | **IMPLEMENTED** |
| Stale proposal revalidation before execute | **IMPLEMENTED** |
| Auto-resolution when state changes | **IMPLEMENTED** |
| External writes gated by `ExternalActionPolicy` (`read` / `suggest` / `draft` / `execute`) | **IMPLEMENTED** |
| Unresolved / ambiguous identity blocks send | **IMPLEMENTED** |
| Proactive Center `/lavr/proactive` | **IMPLEMENTED** |
| Today (top high/critical only) + Executive Brief (non-commitment high/critical) | **IMPLEMENTED** |
| Person / Project / Commitment pending proposals | **IMPLEMENTED** |
| Telegram Owner compact alert + `proactive_{id}` deep link | **IMPLEMENTED** |
| Settings → Proactivity (alerts, quiet hours, cap, auto-reminder, auto-draft, third-party execute off) | **IMPLEMENTED** |
| Scheduler `operational-control:scan` every 10 minutes + event-driven hooks | **IMPLEMENTED** |
| B.2 `jarvis:proactive:dispatch` heuristics | **kept** (separate from Phase 11) |
| **LIVE PROACTIVE CAMPAIGN** | **NOT VALIDATED** |

Lightweight `AutomationEvent` DTO remains a log line. It is **not** the operational event store.

## Event statuses

`observed` → `assessed` → `actionable` → `dismissed` / `resolved` / `superseded`

## Proposal statuses

`pending` → `approved` / `executed` / `dismissed` / `expired` / `failed`

Snooze uses `snoozed_until` on a still-pending proposal.

## Rules (typed)

- overdue commitment → `remind_person`
- likely_done → `confirm_commitment`
- detected commitment → `review_detected_commitment`
- missing follow-up after quiet overdue
- important unanswered email (explicit request + known Person + Project + no thread reply; **UNKNOWN ≠ EMPTY**; blocked mailbox does not infer no-reply)
- blocked integration (`blocked_auth`) → `reconnect_integration` once
- repeated automation failure (3 in 6h)
- project stale (active obligations + unresolved blocker + no source activity)
- meeting risk / unresolved actions
- telegram blocker (grounded)
- decision-like without next action (not a first-class Decision)

## Policy

| Action | Default |
| --- | --- |
| read / suggest / draft | allowed (draft only if setting) |
| create personal reminder | may execute if auto-create reminders |
| send email / Telegram to a person / calendar write | **suggest**; execute off |
| confirm commitment | Owner intent |

Escalation raises **notification priority**, never send permission.

## Handover (Phase 12)

Proactive events/proposals carry `source_type` / `source_id` / `source_external_id` / `evidence_pointer`. Phase 12 handover cleanup **IMPLEMENTED** (dry-run default). Canonical confirmed commitments/people/projects stay. Do not add a global `is_test` flag. Live cleanup against developer accounts: **NOT VALIDATED**.

## Not in Phase 11

Autonomous employee messaging; unrestricted Gmail/Telegram send; generic no-code rules; ML personalization; surveillance / personality; first-class Decisions; SaaS; multi-user; connecting real developer accounts.

---

## TARGET — CEO operating patterns

Not implemented rules. Do not add them to the scanner from this list. Each future rule must cite evidence. The model must not invent a failure.

Candidates:

- missing owner
- missing deadline
- missing KPI
- missing Definition of Done
- repeated miss
- decision without a next action (the current decision-like rule is the only one of these that exists)
- unresolved decision
- CEO doing work that already has a manager owner
- too many priorities
- activity with no measurable output
- role ambiguity
- data contradiction across sources
- a project topic reopened without a new decision

Still forbidden: surveillance, personality labels, employee ranking, autonomous messages to staff.
