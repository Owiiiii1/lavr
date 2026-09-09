# Executive Brief

Canonical briefing product. Automation/validation: [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md). Domain: [DOMAIN_MODEL.md](DOMAIN_MODEL.md).

The Daily Executive Brief is a **central scenario**. Principle: **reduction to attention**, not volume.

---

## CURRENT

Two related but different mechanisms:

1. **Scheduled Reports** (`scheduled_reports`) — named, persisted, multi-source, local clock. Types: `daily_plan`, `tomorrow_plan`, `mail_groups_digest`, `custom_composite`. Workspace Center **Отчеты**. Tools `create_scheduled_report` etc. Dispatch `jarvis:reports:dispatch`.
2. **Productivity briefs** — opt-in Daily/Evening/Weekly (`user_productivity_settings`), default off. Skipped if an active Scheduled Report already covers the same plan type.

Neither is a full Executive Brief with Today / Inbox / Team / Commitments Due / Overdue / Waiting For / Decisions Needed / Risks / Follow-ups as first-class sections over operational People/Commitments/Meetings.

Composer already has collect + phrasing validation + deterministic fallback (after 2026-09-09 incident fixes). Still not the TARGET section set or validation bar.

---

## TARGET

First-class brief (daily and weekly) produced by the Automation Engine.

Illustrative schedule:

```text
Daily 08:00
Sources:
- email
- calendar
- telegram
- commitments
- overdue
- meetings

Format:
executive_brief
```

Pipeline: **COLLECT → NORMALIZE → ANALYZE → VALIDATE → RENDER → DELIVER**  
([AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md)).

### Sections

| Block | Content |
| --- | --- |
| Today | Meetings / events |
| Inbox | Only important changes |
| Team | What happened with people / teams |
| Commitments Due | Promised for today |
| Overdue | Late |
| Waiting For | What the CEO waits on |
| Decisions Needed | Needs CEO decision |
| Risks | What may slip |
| Follow-ups | Who is worth writing |

Empty sections are omitted or marked empty **after** validation — not dumped as “N/A” technical stubs.

### Data sources

Operational DB first (calendar bindings, commitments, tasks, meetings). Live mail/calendar/Telegram collectors second. Synthesis is a helper, not a substitute for missing structured rows.

### Delivery

| Channel | Rendering |
| --- | --- |
| Telegram Chat | Short executive text; no IDs, no subject dumps |
| Voice | Same canonical text, spoken |
| Web / WebApp | Same content, richer layout (Today) |

Scheduled Report types should **gain** `executive_brief` (or equivalent) rather than invent a third briefing engine. Productivity briefs that duplicate it should remain skipped.

Phase 8.
