# Reminders

> **CURRENT.** Timed personal nudges. Not Watchers, not Scheduled Reports, not Commitments. Routing: [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md). LAVR is single-user.

Собственная подсистема. **Не** Google Calendar. **Не** Tasks.

**Status.** Phase B.1 — Reminders 2.0: Owner **MANUAL PASS for confirmed live core flow** (Web Push, Reminder Center, basic user flow). Not exhaustive DST/recurrence/multi-device MANUAL PASS.

M25U.3.1 remains the create-without-Telegram baseline. This milestone adds Web Push, Reminder Center v2, edit / snooze / done / cancel, recurrence, and per-channel delivery.

Phase B.2 adds optional `reminders.task_id` and shows «Связано с задачей» in Reminder Center. Tasks remain a separate domain. [TASKS.md](TASKS.md).

---

## Product model

Reminder is a **Core object**. Telegram and Web Push are **independent delivery adapters**.

Lifecycle (Core):

```
scheduled → (due / processing) → delivered | completed | cancelled | failed
```

- **Delivered** — at least one adapter successfully notified the user.
- **Done / completed** — the user closed the reminder. Distinct from delivered.
- **Cancelled** — the user (or disabled-user path) stopped it.
- Missing both channels is **not** a Core failure. The row stays `scheduled` / due.

One reminder may have Web Push, Telegram, both, or neither.

Scheduled Reports reuse the same Telegram adapter (`SendsReminderTelegram`) and Notification Center / Web Push path. They are not reminder rows.

---

## Schema (additive, Phase B.1)

Existing `reminders` plus:

| Change | Meaning |
| --- | --- |
| `reminders.completed_at` | user Done |
| `reminders.status` | also `completed` (string column; no destructive enum migration) |
| `reminder_deliveries` | per-channel status, attempts, `delivered_at`, `last_error`, `next_retry_at` |
| `reminder_occurrences` | fired occurrence history for recurring series |
| `push_subscriptions` | browser PushManager subscription owned by `user_id` |

`recurrence_rule` stores a simple token: `daily` \| `weekdays` \| `weekly` \| `monthly`. Not a full RRULE engine.

Core `metadata` still holds dispatcher bookkeeping (`attempts`, `next_retry_at`, `delivery_state`, per-channel attempt counts, occurrence snapshots). Per-channel truth lives in `reminder_deliveries`.

---

## Recurrence

Same reminder row **advances `run_at`**. History is written to `reminder_occurrences` (and a bounded `metadata.occurrence_history` snapshot).

DST: next occurrence is computed from **local wall clock** in the reminder IANA timezone (`Europe/Rome` 09:00 stays 09:00 across DST). Carbon `create(..., timezone)`, not a homemade calendar parser.

Done on a recurring reminder completes **this occurrence** and schedules the next. Cancel stops the series.

---

## Delivery

`jarvis:reminders:dispatch` every minute.

1. Claim due Core rows (`scheduled`, `run_at <= now`, retry due).
2. Run adapters independently:
   - Telegram if `ChannelIdentity` is linked
   - Web Push to **all active** subscriptions of that user
3. Persist `reminder_deliveries`.
4. Core status:
   - any adapter success → `delivered` (one-shot) or record occurrence + advance (recurring)
   - no adapters → `scheduled`, `delivery_state=no_channel`, recheck 30 minutes
   - all available adapters fail after bounded retries → `failed` (one-shot) or record failed occurrence + advance (recurring)
5. Adapter error on one channel does **not** void success on the other.

### Telegram retry

Unchanged: max 3 send attempts, backoff 1 then 2 minutes, then fail that channel.

### Web Push retry

Transient (5xx / 429 / network): bounded retry (max 3).
Permanent / expired (404 / 410): **revoke subscription**, do not retry that endpoint.

---

## Web Push

- Library: `minishlink/web-push`
- VAPID: `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` via `config/reminders.php`
- Generate **once**: `php artisan jarvis:reminders:vapid` (does not rotate existing keys)
- Private key never sent to the browser
- Public key is returned to Workspace with the reminders panel JSON
- Service worker: `/reminder-sw.js` (`public/reminder-sw.js`)
- Permission is requested only after **«Включить уведомления»** (user gesture). No prompt on page load.
- UI states: enabled / disabled / browser denied / unsupported
- Click: focus an existing JARVIS client or `openWindow` an **allowlisted** `/jarvis` or `/chat` path built server-side
- Payload keys only: `reminder_id`, `title`, `body` (bounded), `url`, `timestamp`

`p256dh` / `auth` are encrypted at rest. Subscriptions belong to the authenticated user; client cannot pass `user_id`.

---

## Reminder Center v2

Drawer: `resources/js/personal-workspace/RemindersPanel.jsx`

Sections: **Due**, **Сегодня**, **Предстоящие**, **История**.

Actions: Edit, Snooze (+10 мин / +1 час / завтра / своё), **Готово**, **Отменить**.

Source conversation: «Из разговора: …» with an owned `chats.show` link when present.

Badge counts `scheduled` + `processing` only. Recurring parent is one row (no double count). History / done / cancelled are excluded.

Routes (same for `/jarvis` and `/chat`):

- `GET …/reminders`
- `PATCH …/reminders/{id}`
- `POST …/reminders/{id}/snooze|complete|cancel`
- `GET|POST|DELETE …/reminders/push`

Foreign reminder → `404 not_found`.

---

## AI tools

| Tool | Role |
| --- | --- |
| `create_reminder` | create, optional `recurrence` |
| `list_reminders` | disambiguation |
| `update_reminder` | text / time / timezone / recurrence |
| `snooze_reminder` | `10m`, `1h`, `tomorrow`, `custom` |
| `complete_reminder` | user Done |
| `cancel_reminder` | stop |

If several reminders could match, tools return `ambiguous` + candidates and **do not mutate**.

---

## Leftovers (Phase B.2)

- Tasks
- Notification Center of general events
- Daily Brief / Weekly Review
- Proactive suggestions
- Mobile push / native app
