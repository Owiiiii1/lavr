# LAVR — current implementation snapshot

**Date:** 2026-09-09 (Phase 3A Telegram WebApp foundation; runtime as after Phase 1 infrastructure + Owner)  
**Product:** LAVR — personal AI Chief of Staff for one CEO ([PRODUCT.md](PRODUCT.md))  
**Host path:** `/var/www/lavr`  
**Public URL:** https://lavr.youngfashionshow.com  
**GitHub:** https://github.com/Owiiiii1/lavr.git  

This file is a **runtime snapshot**. If it disagrees with older JARVIS prose, **this file and the code win**.

Planned architecture is labeled **TARGET**. Do not treat TARGET as shipped.

---

### Status vocabulary

| Status | Meaning |
| --- | --- |
| CURRENT / IMPLEMENTED | In production code |
| MANUAL PASS | Owner confirmed in production |
| MANUAL PARTIAL | Owner confirmed part of the flow |
| IMPLEMENTED / NOT VALIDATED | Code exists; not Owner-confirmed |
| LIVE BUG | Code exists; Owner reports it does not work as expected |
| TARGET | Specified for later phases; **not** implemented |
| DEFERRED | Explicitly not current work |
| CANCELLED | Will not be built |
| HISTORICAL | Origin JARVIS; not LAVR product rules |

---

## CURRENT vs TARGET (product)

| Topic | CURRENT | TARGET |
| --- | --- | --- |
| Tenancy | Single Owner, no register | Same |
| Role | Personal assistant + knowledge/tasks/watchers/reports | AI Chief of Staff / operational control layer ([PRODUCT.md](PRODUCT.md)) |
| Primary fast UI | Telegram DM | Telegram Chat (same) |
| Primary rich UI | Web Workspace `/lavr` + Mini App entry `/telegram/webapp` (same UI) | Telegram WebApp = same Workspace ([INTERFACES.md](INTERFACES.md)) |
| People | Knowledge entity `person` + synthesis | `people` + roles + `employee_profiles` |
| Organizations | Knowledge entity `organization` | `organizations` + relationships |
| Projects | Work container + pivots | Business context (mailboxes, meetings, commitments, …) |
| Meetings | Calendar live + knowledge events | `meetings` + transcript pipeline (Phase 5A manual; **Phase 5B Zoom automatic**) |
| Zoom Integration | **Absent** | **TARGET Phase 5B / NOT IMPLEMENTED** |
| Commitments | Derived (`CommitmentResolver`, knowledge events) | First-class `commitments` + evidence |
| Decisions | Group knowledge / events | First-class `decisions` |
| Automation | Watchers + scheduled reports + briefs + proactive | Deterministic engine + events + validation ([AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md)) |
| Executive Brief | Scheduled reports + opt-in briefs | Attention-reduced daily/weekly brief |
| Onboarding | Owner profile `completed` (legacy skip) | Business-map onboarding |
| Telegram WebApp | **Foundation IMPLEMENTED / NOT VALIDATED** on a real Telegram client | Same Workspace; HMAC session; Menu Button still manual |

---

## 1. Git

| Item | Value |
| --- | --- |
| Branch | `main` |
| Origin | `https://github.com/Owiiiii1/lavr.git` (`Owiiiii1/lavr`) |

Do not push to `Owiiiii1/JARVIS`. `.env` stays gitignored. Do not commit passwords.

---

## 2. Runtime / stack (CURRENT)

Verified on this dedicated host (Phase 1 infra). Other sites on the host still use php8.3-fpm; LAVR does not.

| Component | This LAVR host |
| --- | --- |
| OS | Ubuntu 24.04.4 LTS |
| Project path | `/var/www/lavr` |
| Public URL | https://lavr.youngfashionshow.com |
| APP_NAME | LAVR |
| Laravel | 13.x (about: 13.30.1) |
| Product model | single-client / single-user |
| PHP for LAVR | **php8.5-fpm 8.5.10**, socket `/run/php/php8.5-fpm.sock`; artisan `/usr/bin/php8.5` |
| System `/usr/bin/php` | php **8.3.6** (other projects) — do not point LAVR at it |
| nginx | 1.24.0; vhost `lavr.youngfashionshow.com` only |
| TLS | Let's Encrypt **CN=lavr.youngfashionshow.com**; HTTP **301** → HTTPS **200** |
| MySQL | Database **`lavr`**, user `lavr`@localhost / @127.0.0.1, grants `lavr.*` only |
| Queue | systemd `lavr-queue.service` (`php8.5 artisan queue:work`) |
| Scheduler | crontab: `cd /var/www/lavr && /usr/bin/php8.5 artisan schedule:run` |

Composer (relevant): `owlsolutions/custom-admin-kit` v0.5.0, Inertia, Ziggy, Nutgram (via kit).

AI / Telegram / ElevenLabs credentials: encrypted DB columns, not `.env`. Do not document secrets.

Detail: [Development/LAVR_PHASE_1_REPORT.md](Development/LAVR_PHASE_1_REPORT.md) sections J–K.

---

## 3. Deployment (CURRENT)

| Item | Actual |
| --- | --- |
| Domain | `lavr.youngfashionshow.com` |
| Document root | `/var/www/lavr/public` |
| TLS | Installed (webroot certbot) for this hostname only |
| Scheduler (app) | `jarvis:reminders:dispatch` 1m; `jarvis:tasks:dispatch` / `jarvis:watchers:dispatch` / `jarvis:reports:dispatch` / `jarvis:proactive:dispatch` 5m; `jarvis:briefs:dispatch` 1m; plus reliability/voice/purge as in `routes/console.php` |
| Telegram queue | host-specific flock worker (deploy crontab) |

Vite production build on deploy (`public/build` gitignored).

---

## 4. Database (CURRENT)

Engine: **MySQL**, database `lavr`. CRM tables dropped historically (M0). App migrations Ran.

**Present:** users, conversations, messages, memories, knowledge_*, tasks, reminders, watchers, scheduled_reports, projects, telegram_groups, integration_accounts, notifications, voice, storage, etc.

**Absent:** `people`, `employee_profiles`, `organizations` (as operational tables), `meetings`, `commitments`, `decisions`, operational `events` bus.

See [DATABASE.md](DATABASE.md) for schema commentary (may still use JARVIS names — code wins).

---

## 5. Product surfaces

### CURRENT

| Surface | Path | Status |
| --- | --- | --- |
| Login | `/` | IMPLEMENTED |
| Personal Workspace | `/lavr` | CURRENT rich UI |
| `/jarvis`, `/chat` | GET redirect to `/lavr` | LEGACY |
| `/cabinet` | compatibility redirects | LEGACY |
| Admin | `/dashboard`, `/settings/*` | IMPLEMENTED (technical) |
| Voice | workspace + sessions | Рация MANUAL PASS; Диалог Beta NOT VALIDATED |
| Storage | `/lavr/storage` | IMPLEMENTED |
| Projects | `/projects` | IMPLEMENTED (work container) |
| Telegram Groups | `/telegram-groups` | IMPLEMENTED / NOT VALIDATED as campaign |
| Telegram WebApp | `/telegram/webapp` → session → `/lavr/today` | IMPLEMENTED / NOT VALIDATED (real Telegram client) |
| Desktop | — | CANCELLED |
| Mobile / Client API | — | DEFERRED |
| Register / user admin | — | Removed (Phase 1) |

Frontend: `resources/js/personal-workspace/PersonalWorkspace.jsx`. Internal route names remain `jarvis.*`.

Workspace: chat + Task / Reminder / Watcher / Report / Notification centers + compact **Обзор** + Voice + Settings. Conversation delete preserves durable Tasks / Knowledge / Memory. **MANUAL PASS** (core daily workflow).

### TARGET

See [INTERFACES.md](INTERFACES.md). Admin stays technical. CEO daily path: Telegram Chat + WebApp/Web.

---

## 6. Implemented capabilities (CURRENT)

Condensed. Layer docs hold detail.

| Area | CURRENT | Validation |
| --- | --- | --- |
| Conversation Engine / C.1 | IMPLEMENTED | MANUAL PASS for tested continuation/reference (not all C.1) |
| Memory Engine | IMPLEMENTED | Core workflow MANUAL PASS (memory vs knowledge) |
| Knowledge Layer | Relational index | MANUAL PASS for tested extract/retrieve |
| Tasks / Reminders | Separate tables | Core chain MANUAL PASS; briefs/proactive NOT VALIDATED as full product |
| Watchers | Bounded conditions | MANUAL PASS **internal task watcher**; Gmail/Calendar/GitHub watchers deferred as campaigns |
| Scheduled Reports | IMPLEMENTED | READY FOR OWNER VALIDATION; 2026-09-09 body bugs **fixed in code** |
| Synthesis E.3 | Derived FactPack; `list_commitments` etc. | MANUAL PASS tested overview/waiting |
| Google Gmail/Calendar | Tools + OAuth | Read/send used live; confirmation UX not MANUAL PASS; no Drive |
| GitHub | Tools + OAuth | NOT VALIDATED campaign |
| Telegram DM | Pairing, text, voice reply MANUAL PASS; voice input NOT VALIDATED |
| Telegram Groups | IMPLEMENTED | NOT VALIDATED campaign |
| Voice Web | Рация PTT MANUAL PASS; C.2 Beta NOT VALIDATED |
| Assistant profile | Defaults; Owner onboarding `completed` | Not business-map onboarding |

Core Daily Workflow (2026-09-07): **MANUAL PASS 10/10** on Validation Core 2. [VALIDATION_CORE_WORKFLOW.md](VALIDATION_CORE_WORKFLOW.md). That is the **tested core chain**, not every subsystem.

Scheduled report incidents (truncated AI; mail digest as subject list; calendar DI; reasoning-model token budget): **fixed in code 2026-09-09**. Next slots still need Owner confirmation. Do not describe the broken bodies as current intended behavior.

---

## 7. Personalization / Owner (CURRENT)

One user: `admin@admin.com`, role `owner`, assistant_name **LAVR**. Password is not recorded in Git.

`onboarding_status=completed` via `AssistantProfileService::defaultsFor` — **legacy skip**, not TARGET CEO onboarding. [ONBOARDING.md](ONBOARDING.md).

---

## 8. What is not here (CURRENT)

- Full Telegram Mini App E2E on a real client (needs bot token + Owner pairing in MySQL `lavr`)
- `people` / `meetings` / `commitments` / `decisions` / org relationship tables
- Zoom Integration: **TARGET Phase 5B / NOT IMPLEMENTED** (no OAuth, webhook, or transcript import)
- Deterministic Automation Engine as specified (watchers/reports exist but are not the full TARGET)
- Executive Brief section model
- Leadership Review
- Multi-mailbox Google (one active account MVP)
- Desktop, Mobile, public registration, Neo4j, wake word, SSE for scheduler events

Live campaigns still open: [DEFERRED_VALIDATION.md](DEFERRED_VALIDATION.md).

---

## 9. TARGET (pointer only)

Do not implement from this section. Plan: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) Phases 3A–12.

Architecture sketch: [DOMAIN_MODEL.md](DOMAIN_MODEL.md). Decisions: ADR-266+ in [DECISIONS.md](DECISIONS.md).
