# Notification Center

> **CURRENT inbox.** TARGET delivery also uses Telegram Chat for alerts/briefs ([INTERFACES.md](INTERFACES.md), [EXECUTIVE_BRIEF.md](EXECUTIVE_BRIEF.md)).

Persistent in-app inbox (`jarvis_notifications`). Distinct from Reminder Center and from Web Push transport.

**Status.** Phase B.2 IMPLEMENTED / NOT VALIDATED.

Types: `reminder_due`, `task_due`, `task_overdue`, `brief_ready`, `proactive_suggestion`, `watcher_triggered`, `scheduled_report_ready`.

Dedupe key is unique per user. Safe action URLs are `/jarvis` or `/chat` only.

See [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md).
