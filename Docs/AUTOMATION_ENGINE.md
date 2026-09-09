# Automation Engine

Canonical automation architecture. Current watcher/report/reminder **implementation** remains in:

- [WATCHERS_AND_AUTOMATIONS.md](WATCHERS_AND_AUTOMATIONS.md)
- [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md) (briefs / proactive)
- [REMINDERS.md](REMINDERS.md)
- [EVENT_MODEL.md](EVENT_MODEL.md)

Those files describe **CURRENT** code. This file defines the **TARGET** engine and how current objects must be interpreted so they do not semantically collide.

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
| **Scheduled Report** | Periodic **composed digest** at clock time | `scheduled_reports` | Same + Executive Brief type |
| **Event rule** | On operational event → automation | Partial (watcher poll + knowledge events + proactive heuristics) | First-class [EVENT_MODEL.md](EVENT_MODEL.md) |
| **Follow-up** | Commitment / waiting tracking | Proactive suggestions | Policy-gated, commitment-linked |

Routing already exists in tools (`CreateReminderTool`, `CreateWatcherTool`, `ScheduledReportIntent`) and must stay strict. Leftover: Gmail digest watchers can still be shaped when report intent does not match (`WatcherDigestRequest`). **Target:** periodic mail digest is only a Scheduled Report.

---

## CURRENT pipeline (facts)

| Job | Interval | Role |
| --- | --- | --- |
| `jarvis:reminders:dispatch` | 1 min | Due reminders |
| `jarvis:tasks:dispatch` | 5 min | Task due/overdue |
| `jarvis:watchers:dispatch` | 5 min | Evaluate watchers |
| `jarvis:reports:dispatch` | 5 min | Scheduled reports |
| `jarvis:briefs:dispatch` | 1 min | Opt-in productivity briefs |
| `jarvis:proactive:dispatch` | 5 min | Heuristic suggestions |

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

Not a rewrite from zero. Tighten CURRENT objects:

- keep reminder / watcher / report routing airtight;
- retire digest-watchers as a report path;
- validation + fallback for all automated AI text;
- stop semantic mix-ups in tool prompts;
- event rules as consumers of [EVENT_MODEL.md](EVENT_MODEL.md);
- no technical leakage to Telegram.
