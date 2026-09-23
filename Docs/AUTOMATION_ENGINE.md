# Automation Engine

Canonical automation architecture. Current watcher/report/reminder **implementation** remains in:

- [WATCHERS_AND_AUTOMATIONS.md](WATCHERS_AND_AUTOMATIONS.md)
- [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md) (briefs / proactive)
- [REMINDERS.md](REMINDERS.md)
- [EVENT_MODEL.md](EVENT_MODEL.md)
- [PROACTIVE_OPERATIONAL_CONTROL.md](PROACTIVE_OPERATIONAL_CONTROL.md)

**CURRENT:** Phase 7 routing + `automation_runs` is **IMPLEMENTED**. Owner live synthetic scenarios A–E: **NOT VALIDATED**. Phase 12 adds scheduler/queue heartbeats and diagnostics only — not a second engine.

Those files describe **CURRENT** code. This file also defines the **TARGET** engine and how current objects must be interpreted so they do not semantically collide.

---

## Problem inherited from JARVIS

Automatic mechanisms have been unstable:

- a scheduled report becomes a reminder;
- a watcher is created incorrectly;
- instead of a report, a list of email subjects is sent;
- the assistant asks the user to run the query themselves;
- Telegram output includes technical details;
- background actions get semantically mixed.

That is **not acceptable** in LAVR.

---

## Architectural rule

**Conversational AI understands intent.  
The Automation Engine guaranteed-executes the action.**

Example. User: «Следи за письмом от Marco и скажи, когда он пришлёт договор.»

AI emits a structured action (illustrative):

```json
{
  "type": "email_watch",
  "person": "Marco",
  "condition": "contract received",
  "delivery": "telegram"
}
```

After persist, AI must **not** re-decide every cycle what to do.

The engine is deterministic: scheduler → watcher/report/rule → condition → match → notification.

ADR-277, ADR-278.

---

## Object kinds — do not collapse them

| Kind | When | CURRENT | TARGET |
| --- | --- | --- | --- |
| **Reminder** | User (or system) acts at a **known time**: «напомни мне…» | `reminders` | Same |
| **Watcher** | **Condition** in the future: «жди письмо…» | `watchers` | Same, hardened |
| **Scheduled Report** | Periodic **composed digest** at clock time | `scheduled_reports` | Same |
| **Executive Brief** | Morning attention layer | `executive_briefs` + `automation_runs` | Evening/weekly UI |
| **Leadership Review** | Weekly process quality | `leadership_reviews` + `automation_runs` | First-class Decisions |
| **Event rule** | On operational event → automation | First-class `operational_events` + typed rules ([PROACTIVE_OPERATIONAL_CONTROL.md](PROACTIVE_OPERATIONAL_CONTROL.md)); lightweight `AutomationEvent` remains a log line | No generic no-code DSL |
| **Follow-up** | Commitment / waiting tracking | In-app commitment notifications + `commitments:refresh-statuses` + Phase 11 proposals | Policy-gated; no silent third-party send |

Routing already exists in tools (`CreateReminderTool`, `CreateWatcherTool`, `ScheduledReportIntent`, `AutomationIntentRouter`) and stays strict. Periodic mail digest is only a Scheduled Report. `WatcherDigestRequest` remains for **legacy evaluation tests**, not the create-tool path.

---

## CURRENT pipeline (facts)

| Job | Interval | Role |
| --- | --- | --- |
| `jarvis:reminders:dispatch` | 1 min | Due reminders (claim → deliver → `automation_runs`) |
| `jarvis:tasks:dispatch` | 5 min | Task due/overdue |
| `jarvis:watchers:dispatch` | 5 min | Claim due watchers → `EvaluateWatcherJob` |
| `jarvis:reports:dispatch` | 5 min | Scheduled reports |
| `jarvis:briefs:dispatch` | 1 min | Opt-in productivity briefs |
| `jarvis:proactive:dispatch` | 5 min | B.2 heuristic suggestions (no external side-effect) |
| `operational-control:scan` | 10 min | Phase 11 observe → assess → propose (plus event-driven hooks) |
| `commitments:refresh-statuses` | 15 min | Deterministic `due_soon` / `overdue`; notifies once per status transition |
| `automation:recover-stale-runs` | 15 min | Processing `automation_runs` older than N minutes → `retryable` / `failed_stale` |

Scheduler only decides **what is due**. Heavy work is in services/jobs. Recurring objects use unique slot/`run_key` plus cache lock.

### Unified result

Every automation flow records `automation_runs` with status:

`success` | `skipped` | `no_change` | `partial` | `failed` | `retryable`

plus reason code, duration, source counts, delivery result, safe error class. No email bodies, transcripts, or secrets.

`run_key` examples:

- `reminder:{id}:2026-09-10T09:00`
- `watcher:{id}:poll:2026-09-10T10:15`
- `scheduled_report:{id}:{slot}`
- `commitment:{id}:{status}:v1`

Retry of the same logical run reuses the key. Duplicate scheduler ticks do not double-deliver.

### Report pipeline

COLLECT (independent source collectors, partial allowed) → deterministic sections → optional AI synthesis → `ReportOutputValidator` → render → deliver → record.

If AI is disabled, times out, or fails validation: deterministic fallback is delivered. Empty sections are omitted. Calendar/Gmail failure does not abort the rest (`partial`). Auth revoked marks `blocked` and does not retry forever.

### Watcher contract

Condition + source + interval + cursor fingerprint + enabled + health. Notify on **transition**, not every poll while the condition stays true. LLM memory is not state.

### External actions

`ExternalActionPolicy`: read / suggest / draft / execute. Third-party writes default to **suggest** (Owner `third_party_execute` default off). Watcher `propose_action` and Phase 11 proposals never silent-send email, Telegram to a person, calendar, or CRM. Stale proposals are revalidated before execute.

### Health

Computed `healthy` / `degraded` / `blocked` / `disabled` on reminders, watchers, reports. Workspace shows last human result + badge. Admin `/automation-runs` is the technical log.

Lightweight `AutomationEvent` (`commitment.overdue`, `watcher.matched`, `report.completed`) is still logged. Phase 11 first-class store is `operational_events` + `proactive_proposals`. [EVENT_MODEL.md](EVENT_MODEL.md).

---

Scheduled report compose: collect → deterministic RU sections → optional AI phrase → skip AI if empty / phrasing incomplete / not substantial → deliver. Live bugs (truncated AI, subject lists, calendar DI) were patched; see [CURRENT_STATE.md](CURRENT_STATE.md). This is **not** yet the full COLLECT → VALIDATE → RENDER → DELIVER contract below.

Watcher reactions: notify, create notification/reminder/task, internal analysis, **propose** (not silently execute) external action.

---

## TARGET execution pipeline

1. **Parse intent** (conversation turn only) → structured action JSON.
2. **Validate + persist** the automation object (watcher / report / reminder / event rule).
3. **Engine loop** (scheduler or event consumer): load due objects, **idempotent** slot/cursor.
4. **COLLECT** facts from sources / operational DB.
5. **NORMALIZE** into a typed payload (no raw provider dumps).
6. **ANALYZE** (optional AI) only inside a bounded prompt with that payload.
7. **VALIDATE** output (section below).
8. **RENDER** for the channel (Telegram / Web / voice) — no debug IDs.
9. **DELIVER** + log run.

If validation fails: **deterministic fallback** that is still an executive-quality brief, never a technical dump, never a subject list, never “please run this query”.

### Validation (reports and other AI deliveries)

Reject (and fallback) when:

- data was not actually collected;
- required sections empty;
- raw technical IDs / API / service / debug markers;
- dumb list of email subjects;
- truncated model output;
- missing executive summary when the type requires one;
- format does not match report type.

### Idempotency

One logical fire per `(object_id, slot_key)` (already true for scheduled report runs). Watcher cursors / cooldowns stay. Retries must not double-notify.

### Failure / retry

Classify: auth, quota, empty source, model failure, validation failure. Auth failures block without hammering (already watcher policy). Model failure → fallback, not silence-as-success.

### Logging

Every run: inputs collected (counts, not raw secrets), validation result, whether AI or fallback, delivery channels, error code. CEO-facing text never includes those internals.

### Notification delivery

Telegram Chat for alerts and short briefs. Web Notification Center for inbox. Voice rendering is a delivery of the same canonical text. [EXECUTIVE_BRIEF.md](EXECUTIVE_BRIEF.md).

---

## Proactive levels

LAVR is proactive, not chaotic.

| Level | Meaning |
| --- | --- |
| Detect | See event / commitment |
| Track | Persist and monitor |
| Notify | Tell the CEO |
| Suggest action | Propose follow-up |
| Execute action | Only if **policy explicitly allows** |

Default third-party contact: ask first. [COMMITMENTS.md](COMMITMENTS.md).

---

## Hardening work (Phase 7)

**IMPLEMENTED** 2026-09-10. See [Development/LAVR_PHASE_7_REPORT.md](Development/LAVR_PHASE_7_REPORT.md). Executive Brief (Phase 8) hangs on the same `automation_runs` contract (`AutomationType::ExecutiveBrief`). Phase 11 added typed operational rules and `operational_events` — **not** a custom Zapier DSL.

Watchers and scheduled reports may name `integration_account_id`, `project_id`, or a Telegram group. Omit account id to use all enabled Gmail accounts. Reminders stay unrelated to integrations. [MULTI_SOURCE_INTEGRATION.md](MULTI_SOURCE_INTEGRATION.md).

---

## Operating rhythm (TARGET rule)

Automation may enforce a rhythm. It must not become autonomous management.

Allowed examples: remind the Owner, surface a missing deadline, request confirmation, propose a follow-up, create a draft, suggest a review time.

Third-party sends and calendar writes stay policy-gated. [PROACTIVE_OPERATIONAL_CONTROL.md](PROACTIVE_OPERATIONAL_CONTROL.md).

AI interprets, summarizes, and suggests. Code owns schedules, state, evidence, execution policy, and idempotency. AI is not the database.
