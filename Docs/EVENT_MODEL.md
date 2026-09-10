# Event model

Canonical operational events. Automation consumers: [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md).

## CURRENT

There is no operational `events` table.

Existing event-like things:

- `knowledge_events` — timeline facts in the Knowledge Layer (including `commitment_made`, `watcher_triggered`, calendar-related types);
- `watcher_occurrences` — watcher firings;
- `scheduled_report_runs` — report runs;
- `reminder_occurrences` / `reminder_deliveries`;
- `automation_runs` — unified execution/audit rows (no payload bodies);
- lightweight `AutomationEvent` log lines (`commitment.overdue`, `watcher.matched`, `report.completed`) — **not** a bus table;
- `tool_execution_logs`;
- Laravel jobs / scheduler ticks (not a product event log).

Proactive suggestions are heuristic, not an event bus.

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

Phase 7 added operational **run records**, not a full event bus. Automations subscribe to typed domain events in code. Full event bus remains Phase 11. Do not silently treat `knowledge_events` as that bus.
