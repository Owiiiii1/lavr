# Commitments

Canonical commitment model. Tasks: [TASKS.md](TASKS.md). Meetings: [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md). Automation: [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md).

A **Commitment** is one of LAVR’s central entities.

> Someone explicitly or with sufficient confidence undertook to perform an action or deliver a result.

---

## Task vs Commitment

| | Task | Commitment |
| --- | --- | --- |
| What | Formal work item in LAVR | A person’s promise / agreement |
| Typical origin | Created by CEO or system | Extracted from meetings / mail / chat |
| Close | Task workflow | Evidence or explicit confirmation |
| May spawn | Subtasks, reminders | Task, Watcher, follow-up |

Do not mix these concepts. A commitment **may** create a Task or Watcher; it is not itself a Task.

---

## CURRENT

There is **no** `commitments` table.

Approximate behavior:

- Knowledge event types / relations (`commitment_made`, `waiting_on`, `committed_to`);
- `CommitmentResolver` in cross-source synthesis;
- tool `list_commitments` (`mine` / `others` / `all`);
- enum `CommitmentStatus`: `open` / `fulfilled` / `cancelled` / `superseded` (used on derived items, not a table);
- proactive suggestion type `commitment_due`.

This is a **derived view**. It is not tracking with evidence, due_soon/overdue state machine, or automatic extraction from Zoom.

---

## TARGET

### Minimum model

- person
- action
- expected_result
- deadline
- source (meeting, email, message, …)
- project
- status
- confidence
- evidence
- created_at, completed_at

Example: Person `Коля` · action `отправить презентацию` · deadline `2026-09-10 12:00` · source `Meeting #184`.

### Statuses

`detected` → `open` → `due_soon` / `overdue` → `likely_done` → `confirmed`, or `cancelled`.

- AI may set **likely_done** when evidence appears.
- **Confirmed** requires explicit evidence or CEO confirmation.

### Automatic extraction

The CEO must **not** have to say «поставь это на контроль».

If in a meeting someone says «завтра до обеда отправлю презентацию», LAVR should:

1. recognize a commitment;
2. resolve Person;
3. resolve action;
4. resolve deadline;
5. link Meeting / Project;
6. store a structured row;
7. put it on control.

Normal system behavior (Phase 6), not a special command.

### Completion evidence

Do not close only by hand.

Look for evidence: email, attachment, Telegram message, next meeting, external API, document, explicit CEO confirmation.

Example: commitment «прислать PDF» + email from that Person with a PDF → `likely_done` + notify:

> «Похоже, обязательство выполнено: от Коли пришло письмо с нужным PDF.»

### Follow-up

Track overdue, expected mail, missing confirmation, approaching deadlines.

**Default:** ask the CEO before writing to third parties:

> «Коля просрочил обещание на 4 часа. Напомнить ему?»

Writing to employees automatically requires an explicit policy. [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md). ADR-280.
