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
| ExecutiveBrief | Morning `executive_briefs` |
| LeadershipReview | Grounded `leadership_reviews` |
| OperationalEvent | First-class `operational_events` |
| OwnerContextSource | Private upload or manual source. File bytes stay on the local disk, not in the row |
| OwnerContextItem | One claim: category, fact class, scope, sensitivity, status, evidence. Optional `scope_id` points at an existing person, project, or organization |
| ProactiveProposal | `proactive_proposals` + audits |
| BusinessMapProgress | Non-blocking setup (`business_map_progresses`) |
| ValidationBatch | Synthetic validation provenance |
| HandoverCleanupReport | Cleanup plans/results (no secrets) |

**Not in code:** first-class `decisions`. Calendar events and Knowledge events are not Meetings.

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

**CURRENT:** group knowledge type `decision` and Knowledge events exist; Meeting Intelligence stores decisions inside analysis JSON; Executive Brief may surface decision-like items; Proactive Control can flag a decision-like item with no next action. There is no `decisions` table.

**TARGET:** a decision ledger with status, owner, date, rationale, source, execution status, and superseded history. Not a schema. Protocol: [CEO_OPERATING_SYSTEM.md](CEO_OPERATING_SYSTEM.md).

---

## TARGET concepts (not in code)

These names are product language. Do not add models from this section. CURRENT column is what already covers part of the job.

| Concept | CURRENT substitute | TARGET |
| --- | --- | --- |
| CEO Profile | Timezone, locales, assistant `about_user` (how the assistant speaks, not an operating profile) | Stable goals and operating principles. [OWNER_CONTEXT_AND_MEMORY.md](OWNER_CONTEXT_AND_MEMORY.md) |
| CEO Pattern | Weekly review ratios; per-meeting indicators on one transcript | Isolated → repeated → improving → resolved, with evidence counts. Not a table today |
| Weekly Outcome | Morning brief and open commitments | 3–5 company outcomes and 1–3 per manager: owner, result, measure, review period, status, blocker, source, next review. Seven “top” items are not top priorities |
| Decision | Analysis JSON, brief items, proactive “decision-like” rule | Ledger above |
| KPI Definition | Numbers inside notes, mail, or meeting text | A named definition that is not silently overwritten |
| Definition of Done | Commitment `expected_result` when the Owner or analysis filled it | Explicit acceptance criteria on the promise |
| Lesson Learned | Leadership findings for one period | Context, source, observed outcome, reusable rule, date, related project or person. No motivational slogans |
| Meeting type / purpose | One meeting pipeline for every transcript | Labels such as general, 1:1, project review, strategy, sales, finance. Not modes today |
| 1:1 review context | Per-meeting review of a selected person | Pre-meeting brief plus a manager operational review that is not an HR score |

Weekly Outcome ≠ Commitment ≠ Task ≠ Decision.

- Task: something to do.
- Commitment: an explicit promise.
- Weekly Outcome: a business result that matters this period.
- Decision: a choice that changes later execution.

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
