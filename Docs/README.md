# LAVR documentation

**Product:** [PRODUCT.md](PRODUCT.md)  
**Running system:** [CURRENT_STATE.md](CURRENT_STATE.md)  
**Next build:** [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md)  
**ADRs:** [DECISIONS.md](DECISIONS.md) (JARVIS history + LAVR ADR-266+)

If a document disagrees with code, **code wins**. If a JARVIS-era file disagrees with PRODUCT / CURRENT_STATE / IMPLEMENTATION_PLAN, **those three win**.

Deep rewrite: Phase 2 (this index). Origin: [LAVR_MIGRATION.md](LAVR_MIGRATION.md).

---

## Canonical (start here)

| Topic | Doc |
| --- | --- |
| Product | [PRODUCT.md](PRODUCT.md) |
| Interfaces (Telegram / WebApp / Web / Admin) | [INTERFACES.md](INTERFACES.md) |
| Operational core overview | [DOMAIN_MODEL.md](DOMAIN_MODEL.md) |
| People, roles, orgs, relationships | [PEOPLE_AND_RELATIONSHIPS.md](PEOPLE_AND_RELATIONSHIPS.md) |
| Projects / business contexts | [PROJECTS.md](PROJECTS.md) |
| Meetings / transcripts | [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md) |
| Commitments (≠ Tasks) | [COMMITMENTS.md](COMMITMENTS.md) |
| Automation Engine | [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md) |
| Events | [EVENT_MODEL.md](EVENT_MODEL.md) |
| Executive Brief | [EXECUTIVE_BRIEF.md](EXECUTIVE_BRIEF.md) |
| Sources | [DATA_SOURCES.md](DATA_SOURCES.md) |
| Leadership Review | [LEADERSHIP_REVIEW.md](LEADERSHIP_REVIEW.md) |
| Onboarding | [ONBOARDING.md](ONBOARDING.md) |
| Runtime snapshot | [CURRENT_STATE.md](CURRENT_STATE.md) |
| Implementation phases 3–10 | [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) |

Each domain concept has **one** canonical file. Other docs link; they must not restate the full model.

---

## CURRENT implementation (keep; not product vision)

Use these for **how the code works today**. They may still say “Jarvis”. Prefix mentally with CURRENT.

| Topic | Doc |
| --- | --- |
| Conversation / C.1 | [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md), [HUMAN_LIKE_ASSISTANT.md](HUMAN_LIKE_ASSISTANT.md) |
| Memory | [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md), [CONTEXT_BUDGET.md](CONTEXT_BUDGET.md) |
| Knowledge index | [KNOWLEDGE_LAYER.md](KNOWLEDGE_LAYER.md) |
| Synthesis (derived commitments/people) | [CROSS_SOURCE_SYNTHESIS.md](CROSS_SOURCE_SYNTHESIS.md) |
| Reminders | [REMINDERS.md](REMINDERS.md) |
| Tasks | [TASKS.md](TASKS.md), [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md) |
| Watchers (current) | [WATCHERS_AND_AUTOMATIONS.md](WATCHERS_AND_AUTOMATIONS.md) — TARGET engine is AUTOMATION_ENGINE.md |
| Notifications | [NOTIFICATIONS.md](NOTIFICATIONS.md) |
| Workspace UX | [WORKSPACE_PRESENTATION.md](WORKSPACE_PRESENTATION.md), [CLIENTS/WEB_WORKSPACE.md](CLIENTS/WEB_WORKSPACE.md) |
| Channels adapter | [CHANNELS.md](CHANNELS.md) |
| Telegram groups / voice | [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md), [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md) |
| Voice | [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md), [CLIENTS/VOICE_UI.md](CLIENTS/VOICE_UI.md) |
| Storage / research | [STORAGE.md](STORAGE.md), [WEB_RESEARCH.md](WEB_RESEARCH.md) |
| Integrations | [INTEGRATIONS.md](INTEGRATIONS.md) |
| Personalization | [ASSISTANT_PERSONALIZATION.md](ASSISTANT_PERSONALIZATION.md) |
| Schema / HTTP | [DATABASE.md](DATABASE.md), [API.md](API.md) |
| AI providers | [AI_PROVIDER_ARCHITECTURE.md](AI_PROVIDER_ARCHITECTURE.md) |
| Architecture modules | [ARCHITECTURE.md](ARCHITECTURE.md) |
| Validation | [VALIDATION_CORE_WORKFLOW.md](VALIDATION_CORE_WORKFLOW.md), [DEFERRED_VALIDATION.md](DEFERRED_VALIDATION.md) |
| Phase 1 / 2 reports | [Development/LAVR_PHASE_1_REPORT.md](Development/LAVR_PHASE_1_REPORT.md), [Development/LAVR_PHASE_2_REPORT.md](Development/LAVR_PHASE_2_REPORT.md) |

---

## Classification of older files

| Class | Meaning | Files |
| --- | --- | --- |
| KEEP | Accurate CURRENT tech | Layer docs in the table above; Phase 1 report; validation |
| UPDATE | Rewritten in Phase 2 | CURRENT_STATE, IMPLEMENTATION_PLAN, PROJECTS, ARCHITECTURE (banner + target), README, this index |
| HISTORICAL | Origin JARVIS; banner added | JARVIS_USER_OVERVIEW, JARVIS_PITCH, DEVELOPMENT_PHASES, Cursor_Work_Report, many early ADRs |
| DEPRECATED as source of truth | Do not implement from these | ROADMAP (A–E plan), PROJECT.md (pointer only), USERS_AND_CABINET, USER_ADMINISTRATION, CLIENTS/MOBILE_APP, CLIENTS/CLIENT_API (deferred) |

Historical files are **not deleted**.

---

## Status vocabulary

| Status | Meaning |
| --- | --- |
| TARGET / PLANNED | Intended; not in product code |
| IMPLEMENTED | Present in production code |
| IMPLEMENTED / NOT VALIDATED | Code exists; Owner has not confirmed |
| MANUAL PARTIAL | Owner confirmed part of the flow |
| MANUAL PASS | Owner confirmed in production |
| DEFERRED | Explicitly not current work |
| CANCELLED | Will not be built |
| HISTORICAL / SUPERSEDED | Kept for trace; do not implement |
