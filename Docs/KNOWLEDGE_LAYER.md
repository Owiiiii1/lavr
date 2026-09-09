# Knowledge Layer

> **CURRENT index.** Operational People / Meetings / Commitments are **TARGET** tables, not this index. See [DOMAIN_MODEL.md](DOMAIN_MODEL.md), [PEOPLE_AND_RELATIONSHIPS.md](PEOPLE_AND_RELATIONSHIPS.md). Knowledge remains documents + provenance index.

**Status.** Phase E.1 **IMPLEMENTED / NOT VALIDATED**. Not MANUAL PASS. Watchers are Phase E.2 ([WATCHERS_AND_AUTOMATIONS.md](WATCHERS_AND_AUTOMATIONS.md)). Cross-source synthesis is Phase E.3 ([CROSS_SOURCE_SYNTHESIS.md](CROSS_SOURCE_SYNTHESIS.md)).

Knowledge is a structured, source-grounded index on top of Memory, Projects, Tasks, Reminders, Storage, conversation history, and (during normal tool use) compact integration facts.

It does **not** replace Memory.

| Layer | Answers |
| --- | --- |
| Memory | What Jarvis remembers (durable facts, preferences, instructions) |
| Knowledge | Which entities exist, how they are related, what happened, and from which sources that is known |
| Projects | Authoritative project domain (name, status, description, attachments) |
| Timeline | Indexed factual activity (`knowledge_events`), not a second log of raw messages |

See [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md), [PROJECTS.md](PROJECTS.md), [CONTEXT_BUDGET.md](CONTEXT_BUDGET.md).

---

## Principles

Additive. Source-grounded. Bounded. Queryable. Owner-safe (`user_id` is the graph). Idempotent. Provider-neutral. Not auto-injected wholesale. Not a second Memory Engine. Not a crawler. Not Neo4j.

Relational tables in the existing Laravel database.

---

## Data model

| Table | Role |
| --- | --- |
| `knowledge_entities` | Semantic index: person, project, organization, product, place, topic, system, file, custom |
| `knowledge_entity_aliases` | Alternate names (`YFS` → Young Fashion Show) |
| `knowledge_relationships` | Controlled typed links; deactivate rather than delete |
| `knowledge_events` | Timeline facts with `source_fingerprint` |
| `knowledge_event_entities` | Event ↔ entity pivot |
| `knowledge_entity_sources` | Provenance |
| `knowledge_analysis_runs` | Async extraction observability (Core Reliability) |

A Knowledge **project** entity may store `project_id`. The `projects` row remains canonical. Knowledge does not compete on project status/name.

People: name, aliases, sourced org/role/summary, relationships, provenance. No invented contacts. No religion/politics/health/sexuality/ethnicity fields.

---

## Ingestion

All writes go through `KnowledgeIngestionService`.

**Deterministic** (no LLM): task created/completed, reminder created, project created/archived, stored file ready. Optional compact ingest from Gmail/Calendar/GitHub **tool results during a user turn** (`KnowledgeToolResultIngestor`). E.2 watchers may ingest a **new** matching external observation as a Knowledge event with a distinct fingerprint; they do not poll the whole mailbox/repo/calendar. Newly recorded events dispatch matching knowledge watchers locally.

**AI extraction** (Analysis AI, not Conversation AI): bounded text from a newly written Memory or conversation summary. Job: `ExtractKnowledgeFromSourceJob` on the memory/knowledge queue. Explicit vs inference; low-confidence relations are not auto-created. Explicit commitments may be stored as `commitment_made` events and `waiting_on` / `committed_to` relations; vague language is skipped. No production-wide historical scan.

Command `jarvis:knowledge:backfill` is dry-run by default (`--user` required, `--conversation` / `--project` / `--limit` / `--since`). Do not run a live backfill as part of this milestone.

---

## Merge / aliases

Same type + same `normalized_name`, or a stored alias, or the same `project_id` / `external_ref`, may auto-link at high confidence.

Different names (`YFS` vs `Young Fashion Show`) stay separate until an alias or explicit link exists. No aggressive auto-merge. No destructive merge tool.

---

## Context

C.1 `WorkingContext` (active project / recent entity labels / current turn) → `KnowledgeRetriever` compact slice → `ContextBudgetManager` slice `knowledge_context`.

Defaults: 3 entities, 5 relations/entity, 5 events/entity, high-confidence only. Overflow drops knowledge **before** memories so the current turn and Memory are not crowded out. The full graph is never injected.

---

## Tools

Read: `search_knowledge`, `get_entity`, `get_entity_timeline`, `get_entity_relationships`, `list_related_entities`. E.3 synthesis tools (`get_synthesis`, `get_person_status`, `list_waiting_for`, `list_commitments`) read this graph plus Tasks/Watchers; they do not write Knowledge. [CROSS_SOURCE_SYNTHESIS.md](CROSS_SOURCE_SYNTHESIS.md).

Write (non-destructive, core confirmation policy): `remember_entity`, `link_entities`, `add_knowledge_note`.

No merge/delete AI tools. Foreign entity ids fail as `not_found`.

---

## Workspace UI

Settings → **Knowledge** (distinct from Memory): search, People, Projects, recent activity, entity detail (summary, relationships, timeline, source count). Person/project cards may show bounded synthesis (open loops, waiting, commitments, blockers, recent activity). No force-directed graph.

JSON: `GET /jarvis/knowledge`, `GET /jarvis/knowledge/entities/{entity}` and `/chat` mirrors. User-scoped. No public graph API.

---

## Isolation / privacy

Per-user graph. No owner bypass in Personal Workspace. No cross-user inference. No secrets, tokens, full email bodies, or raw provider responses in metadata. Summaries bounded.

Chat delete: detach provenance (`conversation_id` / `message_id` nulled). Entities/relations/events remain if other sources exist. Purely auto-derived rows with zero remaining live sources become `orphan_candidate`. Manual knowledge survives. Same MANUAL PASS chat-delete contract as Memory: durable facts are not wiped because a chat went away.

---

## Reliability

Same Core Reliability pattern as Memory: classified failures, bounded retries, stale source terminal, `knowledge_analysis_runs.last_error` = category. Stale recovery and the reliability report include knowledge runs.

---

## Not in E.1

Mass historical extraction, CRM/address-book mirror, graph visualization, destructive knowledge tools. Watchers shipped separately in E.2. Cross-source synthesis shipped in E.3.
