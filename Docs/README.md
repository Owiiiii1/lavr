# LAVR documentation

**Product.** LAVR is a dedicated single-client personal AI assistant created from JARVIS. It is not SaaS and is not a public multi-user product. See [LAVR_MIGRATION.md](LAVR_MIGRATION.md).

**Start here**

0. [LAVR_MIGRATION.md](LAVR_MIGRATION.md) — origin JARVIS → dedicated LAVR instance
1. [JARVIS_USER_OVERVIEW.md](JARVIS_USER_OVERVIEW.md) — origin product overview (brand is now LAVR)
2. [CURRENT_STATE.md](CURRENT_STATE.md) — what is actually running
3. [ROADMAP.md](ROADMAP.md) — product direction
4. [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) — next executable work + concise history
5. [ARCHITECTURE.md](ARCHITECTURE.md) — module boundaries
6. [DECISIONS.md](DECISIONS.md) — durable ADRs (includes JARVIS history)

Do not treat old milestone write-ups as current requirements. If a doc disagrees with code, **code wins**. Deep documentation rewrite is deferred to the next documentation phase.

---

---

## Status vocabulary

| Status | Meaning |
| --- | --- |
| PLANNED | Intended; not in product code |
| IMPLEMENTED | Present in production code |
| IMPLEMENTED / NOT VALIDATED | Code exists; Owner has not confirmed this function |
| MANUAL PARTIAL | Owner confirmed part of the flow only |
| MANUAL PASS | Owner confirmed the function in production |
| DEFERRED | Explicitly not current work |
| CANCELLED | Will not be built |
| HISTORICAL / SUPERSEDED | Kept for trace; do not implement |

---

## Domain docs

| Topic | Doc |
| --- | --- |
| Product overview (non-developer) | [JARVIS_USER_OVERVIEW.md](JARVIS_USER_OVERVIEW.md) |
| Users / workspaces | [USERS_AND_CABINET.md](USERS_AND_CABINET.md), [USER_ADMINISTRATION.md](USER_ADMINISTRATION.md) — **SUPERSEDED for multi-user**; LAVR is single-client |
| Channels / clients | [CHANNELS.md](CHANNELS.md), [CLIENTS/WEB_WORKSPACE.md](CLIENTS/WEB_WORKSPACE.md), [CLIENTS/MOBILE_APP.md](CLIENTS/MOBILE_APP.md), [CLIENTS/CLIENT_API.md](CLIENTS/CLIENT_API.md) |
| Conversation / memory | [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md), [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md), [CONTEXT_BUDGET.md](CONTEXT_BUDGET.md) |
| Voice | [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md), [CLIENTS/VOICE_UI.md](CLIENTS/VOICE_UI.md), [HUMAN_LIKE_ASSISTANT.md](HUMAN_LIKE_ASSISTANT.md), [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md) |
| Reminders / tasks / reports | [REMINDERS.md](REMINDERS.md), [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md) |
| Personalization | [ASSISTANT_PERSONALIZATION.md](ASSISTANT_PERSONALIZATION.md) |
| Storage / research | [STORAGE.md](STORAGE.md), [WEB_RESEARCH.md](WEB_RESEARCH.md) |
| Integrations | [INTEGRATIONS.md](INTEGRATIONS.md), [PROJECTS.md](PROJECTS.md), [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md) |
| Schema / HTTP | [DATABASE.md](DATABASE.md), [API.md](API.md) |
| History of phases | [DEVELOPMENT_PHASES.md](DEVELOPMENT_PHASES.md) (archive) |

Desktop client documentation was removed. Cancellation: ADR-235 in [DECISIONS.md](DECISIONS.md).
