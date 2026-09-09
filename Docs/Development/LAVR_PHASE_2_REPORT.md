# LAVR Phase 2 — documentation report

**Date:** 2026-09-09  
**Branch:** `main`  
**Remote:** `Owiiiii1/lavr` only  
**Scope:** documentation-only (no migrations, routes, WebApp, or Automation Engine code)

Product intent after the CEO conversation is now the canonical LAVR model: single-instance AI Chief of Staff; facts in DB; three interfaces to one core; operational entities (People, Projects, Meetings, Commitments, …); deterministic automation.

---

## Docs created

| File | Role |
| --- | --- |
| [PRODUCT.md](../PRODUCT.md) | What LAVR is / is not; LAVR acronym principle |
| [INTERFACES.md](../INTERFACES.md) | Telegram Chat, WebApp (TARGET), Web, Admin |
| [DOMAIN_MODEL.md](../DOMAIN_MODEL.md) | Operational core overview; Knowledge vs operational data |
| [PEOPLE_AND_RELATIONSHIPS.md](../PEOPLE_AND_RELATIONSHIPS.md) | Person, roles, EmployeeProfile, orgs, relationships |
| [MEETING_INTELLIGENCE.md](../MEETING_INTELLIGENCE.md) | Meetings + transcript pipeline |
| [COMMITMENTS.md](../COMMITMENTS.md) | Commitments vs Tasks; extraction; evidence |
| [AUTOMATION_ENGINE.md](../AUTOMATION_ENGINE.md) | Watcher vs reminder vs report; deterministic engine |
| [EVENT_MODEL.md](../EVENT_MODEL.md) | Event taxonomy |
| [EXECUTIVE_BRIEF.md](../EXECUTIVE_BRIEF.md) | Daily/weekly brief |
| [DATA_SOURCES.md](../DATA_SOURCES.md) | Gmail, calendar, Telegram, Zoom, APIs |
| [LEADERSHIP_REVIEW.md](../LEADERSHIP_REVIEW.md) | Meeting execution quality |
| [ONBOARDING.md](../ONBOARDING.md) | TARGET business map vs CURRENT completed skip |
| This report | Phase 2 close-out |

---

## Docs rewritten

| File | Change |
| --- | --- |
| [CURRENT_STATE.md](../CURRENT_STATE.md) | Real LAVR runtime (PHP 8.5 FPM, MySQL `lavr`, nginx/SSL); CURRENT vs TARGET; not planned-as-shipped |
| [IMPLEMENTATION_PLAN.md](../IMPLEMENTATION_PLAN.md) | Phases 3–10 vertical slices |
| [PROJECTS.md](../PROJECTS.md) | CURRENT work container vs TARGET business context |
| [README.md](../../README.md) (repo) | Short entry point; Laravel skeleton removed |
| [Docs/README.md](../README.md) | Canonical index + KEEP/UPDATE/HISTORICAL/DEPRECATED |
| [PROJECT.md](../PROJECT.md) | Pointer to PRODUCT.md |
| [ARCHITECTURE.md](../ARCHITECTURE.md) | CURRENT diagram + TARGET pointers |
| [DECISIONS.md](../DECISIONS.md) | ADR-266–280; ADR-236 marked CURRENT-only for rich UI |
| [LAVR_MIGRATION.md](../LAVR_MIGRATION.md) | Docs rewrite no longer deferred |

---

## Historical (banners; not deleted)

- `JARVIS_USER_OVERVIEW.md`, `JARVIS_PITCH.md`
- `DEVELOPMENT_PHASES.md`
- `Development/Cursor_Work_Report.md`
- Early ADRs 001–265 (origin JARVIS; LAVR ADRs win on conflict)

---

## Deprecated as source of truth (kept)

- `ROADMAP.md` (JARVIS A–E plan) — replaced by IMPLEMENTATION_PLAN Phases 3–10
- `PROJECT.md` (Phase 1 stub)
- `USERS_AND_CABINET.md`, `USER_ADMINISTRATION.md` (multi-user)
- `CLIENTS/MOBILE_APP.md`, `CLIENTS/CLIENT_API.md` (not the WebApp path)

KEEP implementation docs (Conversation, Memory, Knowledge, Watchers, Tasks, …) received **CURRENT** banners so they are not read as TARGET architecture.

---

## Contradictions removed

| Old claim | Now |
| --- | --- |
| Web Workspace is the primary product UI (forever) | CURRENT rich UI; TARGET rich UI = Telegram WebApp; Telegram Chat = fast channel |
| LAVR is a chat+memory assistant | Chief of Staff / operational control layer |
| Tasks doc: “core domain for commitments” | Commitments ≠ Tasks |
| Knowledge `person` = people directory | Index only; TARGET `people` table |
| `list_commitments` = commitment system | Derived synthesis until Phase 6 |
| Project = Owner work container only | Still CURRENT; TARGET = business context |
| Zoom/transcript = Knowledge document | TARGET Meeting object |
| ROADMAP A–E / M25 as next work | Phases 3–10 |
| Docs rewrite deferred | Phase 2 done |
| CURRENT_STATE TLS “planned”, SQLite-era mix | PHP 8.5 FPM, MySQL `lavr`, HTTP 301 / HTTPS 200 |
| Owner onboarding `completed` = done | Legacy skip; TARGET business map |
| Automation = watchers as agent | Conversational AI parses; engine executes |
| JARVIS multi-user still in USERS_* as current | Historical banners |

---

## Current code facts (audit)

Confirmed in `/var/www/lavr` (not from old JARVIS docs):

- Models: User, Conversation, Message, Memory*, Knowledge*, Task, Reminder*, Project, Watcher*, ScheduledReport*, TelegramGroup*, IntegrationAccount, JarvisNotification, UserAssistantProfile, Voice*, StoredFile* — **no** `people` / `meetings` / `commitments` / `decisions` / org-relationship tables
- People today: Knowledge entity type `person`; `get_person_status` / `CommitmentResolver` / `list_commitments` are **derived**
- `CommitmentStatus` enum exists for synthesis (`open|fulfilled|cancelled|superseded`), not the TARGET state machine (`detected|open|due_soon|overdue|likely_done|confirmed|cancelled`)
- Watcher vs Reminder vs Scheduled Report routing exists in tools; leftover Gmail **digest watcher** path still possible if report intent does not match
- Scheduled reports: types `daily_plan|tomorrow_plan|mail_groups_digest|custom_composite`; collect + phrasing validation + fallback (post 2026-09-09 fixes)
- Google: typically one active account (ADR-070); Gmail is live, not mirrored
- Telegram WebApp / Mini App: **absent**
- Workspace: `/lavr`; `/jarvis` `/chat` GET redirect; no `register`
- Owner onboarding completed via `AssistantProfileService::defaultsFor`
- Runtime: PHP 8.5.10 FPM, MySQL database `lavr`, nginx vhost + Let’s Encrypt for `lavr.youngfashionshow.com`, `lavr-queue.service`

---

## Planned architecture (TARGET, not shipped)

Recorded in canonical docs + ADR-266–280:

- Shared Workspace as Telegram WebApp
- Unified Person + roles + EmployeeProfile + Organizations + relationships
- Projects as business contexts + multi-mailbox binding
- Meetings + transcript intelligence + Leadership Review
- First-class Commitments + evidence + follow-up policy
- Operational event bus + Automation Engine hardening + Executive Brief
- CEO business-map onboarding

---

## Open questions

1. Reuse `knowledge_entities` (type person) vs migrate/link to `people.id` (Phase 4 default: Person is canonical; Knowledge points at it).
2. Operational `events` table vs dual-write with `knowledge_events` (Phase 7).
3. How many Google OAuth accounts the CEO actually needs vs ADR-070 MVP (Phase 10).
4. Exact Telegram bot history limits for each live group (document per group in onboarding).
5. Confirmation UX for WebApp vs Telegram Chat for the same pending tool confirmation.
6. Whether `executive_brief` is a new `ScheduledReportType` or a dedicated table (Phase 8 default: extend Scheduled Reports).
7. Identity merge UX: Admin-only first vs Workspace (Phase 4).
8. Transcript storage size / retention vs “full text is source of truth”.
9. Policy language for auto-messages to employees (when, which channel, whose Telegram).
10. Whether existing `Project` rows (if any) map 1:1 to show contexts (Chicago, Miami, …).

---

## Recommended next implementation phase

**Phase 3 — Telegram WebApp foundation** ([IMPLEMENTATION_PLAN.md](../IMPLEMENTATION_PLAN.md)): same Workspace in Telegram Mini App + browser; no new domain tables.

Phase 7 validation/fallback hardening on **existing** scheduled reports can proceed in parallel if morning briefs keep failing in production — still documentation-defined, not a substitute for Phase 3.
