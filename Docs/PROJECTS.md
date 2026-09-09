# Projects / business contexts

Canonical project model. People: [PEOPLE_AND_RELATIONSHIPS.md](PEOPLE_AND_RELATIONSHIPS.md). Sources: [DATA_SOURCES.md](DATA_SOURCES.md).

A **Project** is a business context the CEO manages — not a Topic, not a chat, not a CRM account dump.

---

## CURRENT

`projects` is an **Owner work container**.

Fields: `user_id`, `name`, `normalized_name`, `description`, `status`, `metadata`.

Pivots (relations only; raw data is not copied into the project):

- conversations, topics, memories
- telegram groups (`project_groups`)
- tasks (`tasks.project_id`)

Knowledge may index a project (`knowledge_entities.project_id`). The Project row stays canonical for name/status.

Tools: `get_project_context` (attached material + bounded group knowledge), E.3 `get_project_status` (derived synthesis).

Projects are **not** automatically classified from messages. Attach is explicit (Admin / tools).

This is useful scaffolding. It is **not** yet the business-context model below (no People/Meetings/Commitments/mailbox binding).

Implementation notes from origin JARVIS: [DATABASE.md](DATABASE.md). Do not treat “Owner-only capability vs ordinary users” as a LAVR product rule — LAVR is single-client.

---

## TARGET

Project = first-class **business context**.

Examples: Young Fashion Show, Chicago, Miami, Europe, Partnerships, other shows / directions.

The CEO has many mailboxes and many shows. A question:

> «Что происходит по Chicago?»

must be assembled **by Project**, not by a lucky semantic hit on the word “Chicago”.

### What a project binds

- People
- Organizations
- Meetings
- Emails / mailboxes
- Telegram groups
- Documents
- Commitments
- Tasks
- Decisions
- Risks
- Reports

### Multiple mailboxes

Do not treat Gmail as “a list of Google accounts”.

Each mailbox binds to:

- Project (and/or Organization);
- Purpose / context.

LAVR must know which business context a mailbox belongs to.

**CURRENT constraint:** ADR-070 — one active Google account per owner (MVP). Multi-mailbox is TARGET (Phase 4 / 10). Documented in [DATA_SOURCES.md](DATA_SOURCES.md).

### Telegram groups

A group is a Source. Bind group → project, people, purpose, importance, monitoring policy. [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md) (current adapter). [DATA_SOURCES.md](DATA_SOURCES.md).

### Reuse vs replace

Phase 4 should **evolve** the existing `projects` table and pivots where they fit, not invent a parallel “business context” product with a second name. Missing links (people, meetings, mailboxes, commitments) are new relations, not a second project system.
