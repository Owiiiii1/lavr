# Tasks, productivity, and proactive Jarvis

**Status.** Phase B.2 **IMPLEMENTED / NOT VALIDATED**. Not MANUAL PASS until Owner live test.

Related: [TASKS.md](TASKS.md), [NOTIFICATIONS.md](NOTIFICATIONS.md), [REMINDERS.md](REMINDERS.md), [ROADMAP.md](ROADMAP.md).

---

## Phase B.1 Reminders 2.0

Owner confirmed the live core flow works: **MANUAL PASS for confirmed live core flow**.

That covers:

- Web Push live works
- Reminder Center live works
- basic Reminder 2.0 user flow works

It does **not** claim MANUAL PASS for every DST / recurrence / multi-device / delivery-failure edge case.

---

## Reminder vs Task vs Watcher vs Scheduled Report vs Proactive

| | Reminder | Task | Watcher | Scheduled Report | B.2 Proactive |
| --- | --- | --- | --- | --- | --- |
| Question | When should Jarvis notify me at a **known time** so **I** act? | What do I need to accomplish? | Notify when a **future condition/event** is true | At a **known time**, collect sources and send **one report** | Bounded **heuristic** suggestion |
| Table | `reminders` | `tasks` | `watchers` | `scheduled_reports` | `jarvis_notifications` (`proactive_suggestion`) |
| Example | «напомни в 9 проверить почту» | «сделай отчёт» | «жди письмо от школы» | «каждый вечер в 22 планы на завтра» | overdue high-priority task |

A task is **not** a reminder row. Completing or cancelling a task cancels **future open** linked reminders and keeps history (`reminder_occurrences`, delivered rows). A watcher is **not** a reminder: it evaluates a condition. A scheduled report is **not** a watcher: it does not wait for a source event; it runs at the clock and synthesizes. B.2 proactive remains a separate engine and must not be recreated as implicit watchers.

Watchers: [WATCHERS_AND_AUTOMATIONS.md](WATCHERS_AND_AUTOMATIONS.md).

---

## Tasks

Statuses: `open`, `in_progress`, `completed`, `cancelled`.
Priorities: `low`, `normal`, `high`, `urgent`.

Subtasks: `tasks.parent_task_id`, one level, same user. Completing a parent with open children returns `open_subtasks` unless `force=true`. Children are not destroyed.

Optional relations:

- `source_conversation_id` / `source_message_id` (owned conversation only)
- `project_id` (Owner + owned project; ordinary users always null)
- calendar reference `calendar_provider` / `calendar_id` / `calendar_event_id` (optional; not a Calendar mirror)

Task Center: header **Задачи** on `/jarvis` and `/chat`. Sections: Просрочено, Сегодня, Предстоящие, Без срока, Выполненные.

---

## Notification Center

In-app inbox table `jarvis_notifications`. **Not** a second Web Push stack.

Types: `reminder_due`, `task_due`, `task_overdue`, `brief_ready`, `proactive_suggestion`, `watcher_triggered`, `scheduled_report_ready`.

Dedupe: unique `(user_id, dedupe_key)`. Scheduler ticks do not spam.

Web Push may accompany task/brief/proactive rows via existing VAPID infrastructure. Push failure does not delete the inbox row. Telegram is **not** used for Notification Center events by default. Reminders keep current Telegram behavior.

---

## Briefs and reviews

Per-user opt-in in Workspace settings (Productivity). Defaults: **all off**, including Owner.

| Mode | Default local time | Command |
| --- | --- | --- |
| Daily Brief | 08:00 | `jarvis:briefs:dispatch` every minute |
| Evening Review | 20:00 | same |
| Weekly Review | Sunday 18:00 (`weekday=7`) | same |

**Canonical named reports** are Scheduled Reports (chat-created, persisted `scheduled_reports`). Generic B.2 briefs stay the opt-in unnamed fallback. If the user already has an active `daily_plan` report, Daily Brief is skipped; an active `tomorrow_plan` skips Evening Review. Do not run a watcher digest, a generic brief, and a scheduled report for the same slot.

Sources gathered first (owned tasks, reminders, Owner projects, recent notifications). Phase E.3 optionally adds bounded synthesis: waiting-for, commitments, project changes, top attention items. Optional bounded LLM phrasing. If AI fails **or returns truncated/incomplete phrasing**, the deterministic text is still delivered. Calendar events may appear only when Google Calendar capability exists; a disconnected calendar does not cancel the report. Brief dispatch does **not** poll Google every 5 minutes.

### Scheduled Reports

**Status: IMPLEMENTED / READY FOR OWNER VALIDATION.**

Tables: `scheduled_reports`, `scheduled_report_runs` (unique `scheduled_report_id` + `slot_key`).

At local `daily_local` time Jarvis collects the configured sources, writes a grounded report, and delivers via Notification Center / Web Push and the existing Telegram reminder sender. Partial source failure still sends, with a natural note. Truncated AI phrasing is discarded; the deterministic report is sent instead. The container binds Calendar / Gmail / IntegrationAccount into `ScheduledReportCollector` so optional constructor defaults cannot skip live Google. Source failures log `scheduled_report.source_unavailable` with a reason code (no tokens, no event/mail bodies). Skipped AI phrasing logs `scheduled_report.phrasing_skipped` with `empty` / `incomplete` / `too_short` (no mail text in logs).

`mail_groups_digest` is a **spoken summary**, not a bullet list of `sender — subject`. Deterministic fallback groups important / other / noise (noise as a count) and names sender plus clipped subject only — it must not quote snippets, because email bodies arrive in the sender's language and cannot be retold in Russian without the model. Gmail **snippets** (not bodies) reach the phrasing call and are stripped from `scheduled_report_runs.collected`. `MailTextNormalizer` removes the zero-width padding marketing mail puts in previews before anything is composed or sent to the model. LLM phrasing for this type must retell everything in Russian, merge letters from the same sender, and must not dump the list or invent facts. Owner does not need to rephrase the chat request to get this behavior.

**Phrasing token budget.** `productivity.briefs.phrasing_max_tokens` (1600) sizes every brief and report phrasing call. Reasoning models bill hidden thinking against the same output budget, so a tight cap returns a stub with `finishReason=MAX_TOKENS`, the completeness guard drops it, and every brief silently degrades to deterministic text. Discarded phrasing logs `productivity.phrasing_rejected` with the provider finish reason; lower this value only with a non-reasoning model.

Types: `daily_plan` (period `today`), `tomorrow_plan` (period `tomorrow`), `mail_groups_digest` (period `since_previous_report`, first run last 24h), `custom_composite`.

Sources (semantic, not raw provider queries): `tasks`, `reminders`, `projects`, `synthesis`, `google_calendar` (`calendar_scope=all_relevant` includes primary + selected + «Семья»), `gmail` (read-only, no mark-read/archive/label/reply), `telegram_groups` (stored group messages only), `notifications`.

Tools: `create_scheduled_report`, `list_scheduled_reports`, `get_scheduled_report`, `update_scheduled_report`, `pause_scheduled_report`, `resume_scheduled_report`, `cancel_scheduled_report`. Success claims require `success=true` and `report_id`. Follow-ups update a unique trusted recent report; ambiguous morning reports ask which one.

Scheduler: `jarvis:reports:dispatch` every 5 minutes, timezone-aware, DST-safe, one send per local date+time. Created before today’s slot → today; created after → next day.

Workspace Center **Отчеты** (`?reports=1`). Pause / resume / cancel in UI; create/edit through chat.

Owner must manually cancel misconfigured watchers **#193** (calendar change pretending to be the 22:00 tomorrow plan), **#194** (Gmail digest pretending to be the 08:30 plan), and overlapping Gmail digest **#191** before recreating the three reports in chat. Cursor does not mutate Owner rows.

---

## Proactive Engine

Deterministic Core decides the trigger. LLM may only rephrase.

Allowed B.2 triggers:

- open task becomes overdue
- high/urgent task due within 2 hours

E.3 may add closed extra types through the same dispatcher: `follow_up`, `project_blocked`, `waiting_too_long`, `deadline_risk`, `stale_project`, `commitment_due`. Same opt-in, daily cap, cooldown, quiet hours. No new notification stream.

Anti-spam (actual values):

- `proactive_enabled` default **false**
- max **3** `proactive_suggestion` rows per local day (excludes reminders and briefs)
- cooldown **4 hours** per task source
- unique `dedupe_key` (e.g. `proactive:task_overdue:{id}`)

Scheduler: `jarvis:proactive:dispatch` every 5 minutes.

Watchers are a different product: an **explicit** persisted condition over a source. They also deliver through Notification Center / Web Push. Do not duplicate B.2 heuristics as watchers. [WATCHERS_AND_AUTOMATIONS.md](WATCHERS_AND_AUTOMATIONS.md).

No unsolicited chatter. No external writes.

---

## Schedulers

| Command | Frequency |
| --- | --- |
| `jarvis:reminders:dispatch` | every minute |
| `jarvis:tasks:dispatch` | every 5 minutes (due / overdue inbox) |
| `jarvis:briefs:dispatch` | every minute (opt-in clocks) |
| `jarvis:proactive:dispatch` | every 5 minutes |
| `jarvis:watchers:dispatch` | every 5 minutes (due watchers only) |

Task due keys: `task_due:{id}:{Y-m-d}`, `task_overdue:{id}`.

---

## AI tools

`create_task`, `list_tasks`, `get_task`, `update_task`, `start_task`, `complete_task`, `cancel_task`, `create_subtask`, `link_task_reminder`.

Conservative create policy: explicit request or unambiguous commitment. Ambiguous matches return candidates. Never pass `user_id`.

Context injection: bounded snapshot (overdue count, due-today count, up to 3 high/urgent titles) only when those counts are non-zero. Deep queries use tools. Tasks are **not** written to Memory.

---

## Ordinary user vs Owner

Ordinary user: personal Tasks, subtasks, linked reminders, Task Center, Notification Center, opt-in briefs, opt-in proactive.

Ordinary user **does not** get Owner Projects, Gmail, Calendar, GitHub, groups/admin.

Owner may link a task to an owned Project and optionally store a Google event reference. Calendar **writes** stay on existing Google tools + confirmation policy.
