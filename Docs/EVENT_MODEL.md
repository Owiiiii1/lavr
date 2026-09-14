# Event model

Canonical operational events. Automation consumers: [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md).

## CURRENT (Phase 11)

First-class `operational_events` (2026-09-14). Lightweight `AutomationEvent` DTO remains a **log line**, not this table. Canonical control loop: [PROACTIVE_OPERATIONAL_CONTROL.md](PROACTIVE_OPERATIONAL_CONTROL.md).

Also present (not this table):

- `knowledge_events` — timeline facts in the Knowledge Layer (including `commitment_made`, `watcher_triggered`, calendar-related types);
- `watcher_occurrences` — watcher firings;
- `scheduled_report_runs` — report runs;
- `reminder_occurrences` / `reminder_deliveries`;
- `automation_runs` — unified execution/audit rows (no payload bodies);
- lightweight `AutomationEvent` log lines (`commitment.overdue`, `watcher.matched`, `report.completed`) — **not** the operational store;
- `proactive_proposals` / `proactive_proposal_audits` — Owner next-step objects, keyed off operational events;
- `tool_execution_logs`;
- Laravel jobs / scheduler ticks (not a product event log).

B.2 `jarvis:proactive:dispatch` heuristics remain separate from this bus.

Statuses: `observed` | `assessed` | `actionable` | `dismissed` | `resolved` | `superseded`.

Handover: events/proposals derived only from a removed test source are purgeable. Confirmed canonical commitments/people/projects stay. [HANDOVER_CLEANUP.md](HANDOVER_CLEANUP.md). Live campaign: **NOT VALIDATED**.

Dedupe: unique `(user_id, fingerprint)`. Same fact stays one row (severity may rise). A changed fact uses a new fingerprint.

Payload is minimal JSON. No raw email bodies or transcripts. Each row carries source_type / source_id / source_external_id, canonical person/project/meeting/commitment ids, confidence, evidence_pointer.

---

## TARGET

An event-driven operational layer. Events may trigger automation rules.

### Example types

- `email_received`
- `telegram_message_received`
- `meeting_uploaded`
- `meeting_analyzed`
- `commitment_detected`
- `commitment_due`
- `commitment_overdue`
- `commitment_completed`
- `calendar_event_created`
- `task_created`
- `task_completed`
- `document_uploaded`
- `watcher_matched`

### Producers

Ingestion (Gmail/Telegram/Zoom/uploads), meeting analysis, commitment tracker, task/reminder engines, watchers, schedulers.

### Consumers

Automation Engine (rules, follow-ups, notifications), Executive Brief collectors, audit UI.

### Auditability

Every event: time, type, source ref, related operational ids, payload hash / bounded payload. CEO-facing products show human summaries, not raw event JSON.

Knowledge events may remain the **index timeline**. Operational events are the **automation bus**. Do not silently treat `knowledge_events` as that bus without an explicit Phase 7 decision; default is a dedicated operational event log or a clearly versioned dual-write.

Phase 7 added operational **run records**. Phase 11 added `operational_events` for Owner attention — not a generic Zapier bus. Automations still subscribe to typed domain events in code. Do not silently treat `knowledge_events` as that bus.
