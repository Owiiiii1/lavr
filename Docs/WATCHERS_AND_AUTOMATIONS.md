# Watchers and event-driven automations

> **CURRENT implementation.** Target Automation Engine (deterministic execution, validation, event rules): [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md). Product: [PRODUCT.md](PRODUCT.md).

**Status.** Phase E.2 **IMPLEMENTED / NOT VALIDATED**. Not MANUAL PASS. Phase E.3 synthesis consumes watcher occurrences as indexed evidence ([CROSS_SOURCE_SYNTHESIS.md](CROSS_SOURCE_SYNTHESIS.md)). Phase E as a whole is **not** complete.

Watchers are explicit, bounded, user-scoped conditions: “when X happens, notify / remind / propose Y.” They are **not** an unrestricted autonomous agent, not B.2 proactive heuristics, not Reminders, and **not Scheduled Reports**.

| Object | Question |
| --- | --- |
| Reminder | Notify at a **known time** when the **user** must act (“напомни мне проверить почту”) |
| Scheduled Report | At a **known time**, collect sources and send one coherent report (“каждый вечер в 22 планы на завтра”, “каждое утро дай сводку почты”) |
| Gmail event watcher | Jarvis polls Gmail and notifies when a **matching new message** appears (“жди письмо от школы”, “следи за письмами от @example.com”) |
| Watcher (other) | Notify when a **future condition** is true |
| Task | A **work item** |
| B.2 Proactive | Bounded **heuristic** suggestion over tasks/time (not a persisted user condition) |
| Knowledge Event | An **observed fact** on the timeline |
| Memory | A **durable remembered fact** |

Detail: [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md), [KNOWLEDGE_LAYER.md](KNOWLEDGE_LAYER.md), [REMINDERS.md](REMINDERS.md), [NOTIFICATIONS.md](NOTIFICATIONS.md).

---

## Principles

Explicit. Bounded. Source-grounded. Deterministic evaluators. Idempotent. Auditable. User-scoped. Rate-limited. Confirmation-safe. Provider-neutral. Channel-independent.

No generated PHP/SQL/HTTP. No `eval()`. No generic public webhook receiver. No silent Gmail/Calendar/GitHub writes.

---

## Domain

Tables: `watchers`, `watcher_occurrences` (unique `watcher_id` + `trigger_fingerprint`).

Statuses: `active`, `paused`, `completed`, `failed`, `cancelled`.

Health: `healthy`, `waiting`, `blocked`, `paused`, `failed`.

Mode: `one_shot` (completes after a successful occurrence) or `recurring`.

A `one_shot` watcher on a task source also completes when its condition can no longer match: closing the
watched task finishes an `overdue_by`, `deadline_within`, or open-status `status_equals` watcher and records
`cursor.resolved_reason = task_closed`. It does not finish a `status_changed` watcher, which legitimately
fires on that transition. Synthesis additionally skips watchers whose linked task is already closed, so a
watcher left Active by an earlier release never appears as a pending waiting-for item.

Trigger types (closed set): `knowledge_event`, `task_state`, `reminder_state`, `time_condition`, `calendar_event`, `gmail_message`, `github_event`.

Condition types (closed set): `event_exists`, `entity_event_type`, `status_equals`, `status_changed`, `deadline_within`, `overdue_by`, `new_item`, `sender_matches`, `subject_contains`, `thread_received_reply`, `calendar_changed`, `github_new_commit`, `github_pr_state_changed`, `github_workflow_failed`.

Reactions (closed set): `notify`, `create_notification`, `create_reminder`, `create_task`, `run_internal_analysis`, `propose_action`.

External writes are **never** executed by a watcher. `propose_action` creates a Notification Center item with `pending_action` metadata for later foreground confirmation.

---

## Evaluation pipeline

1. User creates a watcher (chat tool or Workspace Center).
2. First check **establishes a baseline/cursor**. Historical Gmail/GitHub/Calendar/knowledge items do not fire. “Жди новые письма от X” baselines current matches. If the user also asked whether matching mail is **already** in the inbox, chat should `search_gmail` first, then create the watcher — the watcher itself will not later announce those already-seen messages.
3. Later observations are normalized by a source adapter (`WatcherSourceAdapter`).
4. `WatcherConditionEvaluator` is deterministic.
5. Unique fingerprint + cooldown + per-watcher/day + global/day caps suppress spam. Short bursts may aggregate.
6. `WatcherReactionExecutor` runs once per occurrence.
7. External observations that matter may be ingested as Knowledge events (`WatcherKnowledgeBridge`) with a distinct fingerprint. `watcher_triggered` knowledge events do not re-enter the dispatcher.

Internal events (`KnowledgeEventCreated`, task/reminder changes) dispatch `EvaluateWatcherJob` immediately. Time and integration watchers also run from `jarvis:watchers:dispatch` every 5 minutes (`withoutOverlapping`). Jobs use the existing `default` queue.

---

## External polling

Polling happens **only** for active watchers that need it, with bounded queries.

Defaults (config `watchers.cadence`): Gmail/GitHub ~8 minutes, Calendar/internal ~5 minutes. Recurring `schedule.kind=daily_local` **digest** watchers instead run at the user’s local time (default **08:00**, same as the productivity brief; Owner timezone `Europe/Rome`). Gmail **event** watchers stay on the Gmail cadence (not daily). Auth failures block the watcher and notify once (`Watcher needs reconnect`). Transient errors back off. No global inbox/repo/calendar mirror.

Gmail event monitoring is **IMPLEMENTED / READY FOR OWNER VALIDATION**. Canonical source filters: `sender` / `senders`, `sender_domain` / `sender_domains`, `subject`, `thread_id`, or a bounded `query`. Multiple domains compile to one Gmail `OR` query internally. The UI sentence names the domains (“Письма от marcellinequadronno.it и accademiaucraina.it”), not the raw query. Continuous wording (“следи”, “жди письма”, “сообщай когда приходят”) defaults to `recurring` with cooldown 0 so each new matching message can notify. One-shot only when the user clearly wants the first reply. Do not hijack event phrasing into a morning digest. Do not fall back to a Knowledge watcher, reminder, or project watcher if Gmail create fails.

Broken production Knowledge watcher **#190** (“Письма по Танцевальной академии”, `entity_event_type` / `new_email`) is **not** Gmail monitoring. Cursor did not modify it. Owner remediation: cancel #190 in Автоматизации, then in chat: “Следи за письмами от marcellinequadronno.it и accademiaucraina.it и сообщай, когда они приходят.” Inspect the new watcher: Gmail, recurring, domains in the human description, not a daily digest. Send a fresh matching test email, then a second; both should notify after the normal poll interval. Unrelated senders must not notify. “Каждое утро дай сводку почты” now creates a **Scheduled Report**, not a digest watcher. “Напомни завтра проверить почту” still creates a Reminder.

Existing Gmail digest watchers still evaluate if left active. New periodic mail/plan/group **reports** must not be created as watchers. Owner misconfigured objects after the 2026-09-08 report requests: **#193** (calendar change watcher, not 22:00 tomorrow plan), **#194** (Gmail digest, not 08:30 today plan), **#191** (Gmail digest overlapping a 09:00 mail+groups report). Cursor will not patch those rows. Owner should cancel them after deploy and recreate via chat.

---

## Ownership, privacy, retention

Every ref is ownership-checked at create and at execution. Config and occurrences store filters, stable ids, and bounded summaries — not tokens, full bodies, or raw payloads.

Occurrence prune: `jarvis:watchers:prune` (dry-run by default, 90 days). Do not run a live destructive prune as part of this milestone.

Limits (config): 25 active external / 100 internal per user; daily trigger and notification caps.

---

## Workspace

Center **Автоматизации** on the main Workspace chrome (`/jarvis/watchers`, `/chat/watchers`), same pattern as Tasks/Reminders/**Отчеты**. List, simple create, pause/resume/cancel, recent occurrences. Chat remains the primary authoring path. Periodic composite reports live in **Отчеты**, not here.

---

## AI tools

`create_watcher`, `list_watchers`, `get_watcher`, `update_watcher`, `pause_watcher`, `resume_watcher`, `cancel_watcher`, `list_watcher_occurrences`, `run_watcher_now`.

Capability `watchers` (regular users: internal sources; Gmail/Calendar/GitHub remain Owner). Core writes (`provider` null). Changing source/condition resets the baseline so history is not replayed. `run_watcher_now` is a check only.

Tool prompt: Reminder = the user acts at a known time; Scheduled Report = Jarvis collects sources at a clock time; Event = Jarvis polls Gmail and notifies on matching new messages; Task = work item; B.2 proactive is separate. “Проверяй каждое утро почту” / “каждый вечер планы на завтра” → `create_scheduled_report`. “Жди письмо от школы / следи за письмами от @example.com” creates a recurring Gmail event watcher, never a Knowledge watcher and never a digest. If Gmail is disconnected, chat says to connect it; missing scope asks to grant Gmail access; missing sender/domain asks for a filter. Success claims for reports require `create_scheduled_report` success and `report_id`. Success claims for Gmail events require `create_watcher` success and `kind` `gmail_event`.

Existing recurring Gmail digest watchers (`source.digest=true`, `daily_local`) remain evaluable for already-created rows. New NL must not hijack periodic reports into that path. Status of Gmail event watchers: **IMPLEMENTED / READY FOR OWNER VALIDATION**. Cursor did not run live Gmail or create Owner watchers.

---

## Not in E.2

Generic agent loop. Public webhooks. Zapier UI. Automatic external writes. Live provider validation. Production prune. Marking all of Phase E complete.

Synthesis may **suggest** a watcher (“Ты регулярно ждёшь ответы Apple”) but must not auto-create one. Watcher occurrences feed recent-changes / waiting-for; the same real-world event is deduped with Knowledge.
