# Domain model — Operational Core

Canonical overview of LAVR’s structured world. Detail lives in the linked docs. Do not copy those docs here.

Product: [PRODUCT.md](PRODUCT.md). Current tables: [CURRENT_STATE.md](CURRENT_STATE.md), [DATABASE.md](DATABASE.md).

## Knowledge vs operational data

| Layer | What | Role |
| --- | --- | --- |
| **Knowledge** | Documents, texts, emails, transcripts, notes, Knowledge Layer index | Source material and search |
| **Operational data** | People, Organizations, Projects, Meetings, Commitments, Tasks, Decisions, Events, Watchers, Scheduled Reports | Facts the CEO asks about and that automation tracks |
| **Memory** | Durable remembered preferences / instructions / personal facts | Assistant behavior, not the business OS |

Answers about operational facts must use the structured DB first. Semantic memory is additional.

AI-generated interpretations (summaries, extracted commitments) always keep a reference to the source (meeting, email, message). Raw source remains the source of truth for the text. Structured rows are the source of truth for “what we are tracking”.

---

## CURRENT

Implemented operational-ish objects (Eloquent models):

| Object | Table / notes |
| --- | --- |
| User | Single Owner account |
| Conversation / Message | Chat |
| Task | Formal work items |
| Reminder | Timed personal nudges |
| Person | Canonical `people` + `person_roles` + `person_identities` |
| EmployeeProfile | Extension of a Person with role `employee` |
| Organization | Canonical `organizations` |
| DirectoryRelationship | Typed P↔O / P↔P / O↔O links (`directory_relationships`) |
| Project | Owner **business context** (people, organizations, chats, topics, memories, groups, **operational** `project_source_bindings`, optional `meetings.project_id`) |
| SourceItem | Lightweight `source_items` (external ids + metadata; not a mailbox mirror) |
| Meeting | Canonical `meetings` + `meeting_participants` + `meeting_artifacts` + versioned `meeting_analyses` |
| Commitment | First-class `commitments` + `commitment_evidence` + `commitment_status_history` |
| Watcher | Condition monitor |
| ScheduledReport | Clock-time composite digest |
| KnowledgeEntity | Includes type `person` / `organization` / `project` — **index**; optional `canonical_type` / `canonical_id` |
| KnowledgeEvent | Timeline including `commitment_made` etc. |
| TelegramGroup | Group source |
| IntegrationAccount | OAuth connections; **multiple Google accounts** per Owner |
| JarvisNotification | In-app inbox |
| Memory | Personal memory engine |

**Not in code:** first-class `decisions`, operational `events` table. Calendar events and Knowledge events are not Meetings.

`list_commitments` reads first-class `commitments` first. Knowledge `CommitmentResolver` is fallback only when that table is empty for the Owner. Meeting `commitments_detected` remains analysis JSON; high-confidence items can be promoted to `detected` rows. `get_person_status`: Person → first-class commitments → projects → recent meetings → Knowledge. Meeting read tools: `list_meetings`, `find_meeting`, `get_meeting`, `get_meeting_analysis`.

---

## TARGET — Operational Core

```text
People ──┬── Organizations
         └── EmployeeProfile
         └── Relationships (P↔O, P↔P, O↔O)

Projects (business contexts)
  ├── People, Organizations
  ├── Meetings, Emails, Telegram groups, Documents
  ├── Commitments, Tasks, Decisions, Risks
  └── Reports

Meetings → extracted Decisions, Commitments, Tasks, open questions, risks
Commitments ≠ Tasks
Events drive Automation Engine
```

| Entity | Canonical doc |
| --- | --- |
| Person, roles, EmployeeProfile, Organizations, Relationships | [PEOPLE_AND_RELATIONSHIPS.md](PEOPLE_AND_RELATIONSHIPS.md) |
| Project / business context | [PROJECTS.md](PROJECTS.md) |
| Meeting + transcript intelligence | [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md) |
| Commitment | [COMMITMENTS.md](COMMITMENTS.md) |
| Task | [TASKS.md](TASKS.md) — formal system task; do not merge with Commitment |
| Decision | this file, section below |
| Event | [EVENT_MODEL.md](EVENT_MODEL.md) |
| Automation | [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md) |
| Executive Brief | [EXECUTIVE_BRIEF.md](EXECUTIVE_BRIEF.md) |

Conceptual architecture: [ARCHITECTURE.md](ARCHITECTURE.md).

---

## Decisions (TARGET)

First-class `decisions`.

Store at least: decision text, date, participants, project, source, rationale, consequences, related commitments/tasks.

Query: «Почему мы приняли это решение?» must cite a meeting or other source, not a free-floating model opinion.

**CURRENT:** group knowledge type `decision` and Knowledge events exist; there is no `decisions` table.

---

## Target conceptual schema

```text
TELEGRAM CHAT
      │
TELEGRAM WEBAPP
      │
WEB APP
      │
      ▼
CONVERSATION AI
      │
      ▼
OPERATIONAL CORE
├── People
├── Organizations
├── Projects
├── Meetings
├── Commitments
├── Tasks
├── Decisions
├── Events
├── Knowledge
└── Reports
      │
      ▼
AUTOMATION ENGINE
├── Watchers
├── Scheduler
├── Event Rules
├── Follow-ups
└── Notifications
      │
      ▼
SOURCES
├── Gmail
├── Calendar
├── Telegram
├── Zoom
├── Documents
└── External APIs
```
