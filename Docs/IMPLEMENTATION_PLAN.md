# LAVR — implementation plan

Executable work **after Phase 2 (documentation)**. Runtime: [CURRENT_STATE.md](CURRENT_STATE.md). Product: [PRODUCT.md](PRODUCT.md).

This file is the source of truth for **what to build next**. Origin JARVIS milestones (M0–M26, Phases A–E) are **completed or historical**; do not restart them. Details: git history and [DEVELOPMENT_PHASES.md](DEVELOPMENT_PHASES.md) (archive).

[ROADMAP.md](ROADMAP.md) is **deprecated as a plan** (JARVIS A–E). Direction is this file.

Vertical slices. Prefer shipping a thin path through UI + data + one CEO question over a waterfall of tables with no interface.

---

## Completed (do not restart)

| Phase / area | Result |
| --- | --- |
| Origin JARVIS M0–E.3 | Conversation, Memory, Knowledge index, Tasks, Reminders, Watchers, Scheduled Reports, Workspace `/lavr`, Telegram, Voice, Google/GitHub tools |
| LAVR Phase 1 | Single-client product, `/lavr`, no register/user-admin, production PHP 8.5 FPM + MySQL `lavr` + nginx/SSL, one Owner |
| LAVR Phase 2 | This documentation set — operational architecture, CURRENT vs TARGET |
| LAVR Phase 3A | Telegram WebApp foundation: initData auth, shared Workspace shell, Today, bottom nav, placeholders |

Validation of origin flows: [CURRENT_STATE.md](CURRENT_STATE.md), [VALIDATION_CORE_WORKFLOW.md](VALIDATION_CORE_WORKFLOW.md).

---

## Sequence

Order matches dependency: a CEO-facing shell, then people/projects to hang facts on, then meetings → commitments → reliable automation → brief → review → extra APIs.

Existing `projects`, watchers, and scheduled reports are **reused and hardened**, not thrown away.

| Phase | Slice | Canonical docs |
| --- | --- | --- |
| **3** | Telegram WebApp foundation (same Workspace, TG + browser) | [INTERFACES.md](INTERFACES.md) |
| **4** | People / Organizations / Projects as business contexts | [PEOPLE_AND_RELATIONSHIPS.md](PEOPLE_AND_RELATIONSHIPS.md), [PROJECTS.md](PROJECTS.md) |
| **5** | Meetings + transcript import | [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md) |
| **6** | Commitment extraction + tracking | [COMMITMENTS.md](COMMITMENTS.md) |
| **7** | Automation Engine hardening | [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md), [EVENT_MODEL.md](EVENT_MODEL.md) |
| **8** | Executive Brief | [EXECUTIVE_BRIEF.md](EXECUTIVE_BRIEF.md) |
| **9** | Leadership Review | [LEADERSHIP_REVIEW.md](LEADERSHIP_REVIEW.md) |
| **10** | External integrations / dashboards; multi-mailbox | [DATA_SOURCES.md](DATA_SOURCES.md) |

Phase 7 can start **in parallel** with 5–6 for report/watcher validation (already burning in production). Full event bus waits for operational entities.

Onboarding business map ([ONBOARDING.md](ONBOARDING.md)) spans 3–8; do not block Phase 3 on it.

---

## Phase 3 — Telegram WebApp foundation

**Goal.** One responsive Workspace opens as `https://lavr.youngfashionshow.com/lavr` and as a Telegram Mini App. Auth/session for WebApp. Chat + existing centers still work.

**Not in this phase.** New People/Meetings tables; a second React app.

**Exit.** CEO can open full Workspace from Telegram; standalone Web unchanged in behavior.

### Phase 3A — IMPLEMENTED (2026-09-09)

Code is in this repository. Report: [Development/LAVR_PHASE_3A_REPORT.md](Development/LAVR_PHASE_3A_REPORT.md).

| Item | Status |
| --- | --- |
| Same Workspace for browser + Mini App (no second frontend) | IMPLEMENTED |
| `GET /telegram/webapp` + `POST /telegram/webapp/session` HMAC initData auth | IMPLEMENTED |
| Only linked Owner session; unknown Telegram user blocked | IMPLEMENTED |
| Mobile shell + bottom nav + Today + People/Meetings/Commitments placeholders | IMPLEMENTED |
| Current Projects list (work containers, not Phase 4 business context) | IMPLEMENTED |
| Deep-link allowlist (`startapp` / `next`) | IMPLEMENTED |
| Browser login `/` | Unchanged |
| Real Telegram client Mini App E2E | NOT VALIDATED |
| BotFather Menu Button `Open LAVR` | NOT VALIDATED (manual) |
| `php artisan telegram:set-webapp-menu` | Prepared; **not run** (does not change webhook) |

Remaining for later Phase 3 work: live Mini App after production Telegram token + Owner pairing are present in MySQL `lavr`; Menu Button; optional “Open in LAVR” buttons on selected Chat messages.

---

## Phase 4 — People / Organizations / Projects

**Goal.** Unified `people` + roles + `employee_profiles` + `organizations` + relationships. Evolve `projects` into business contexts (mailbox/group bindings as far as Google MVP allows).

**Not in this phase.** Full multi-account Google (may stub bindings on the single connected account). Meeting intelligence.

**Exit.** «Кто такой Коля?» and project membership resolve from structured rows, with Admin merge/confirm.

---

## Phase 5 — Meetings + transcripts

**Goal.** `meetings` + transcript ingest + extraction into structured facts (participants, topics, decisions, tasks, commitments-as-drafts if Phase 6 not done).

**Exit.** A Zoom/upload transcript becomes a Meeting, not only a Knowledge document.

---

## Phase 6 — Commitments

**Goal.** First-class commitments, statuses, evidence, auto-extract from meetings/mail, distinct from Tasks.

**Exit.** «Что Коля обещал?» reads `commitments`, not only synthesis over knowledge events.

---

## Phase 7 — Automation Engine hardening

**Goal.** Deterministic execution; AI parses intent only; validation + fallback; no report↔reminder↔watcher mix-ups; operational events taxonomy.

**Exit.** Morning report cannot silently become a subject list or a reminder. Telegram payloads stay non-technical.

---

## Phase 8 — Executive Brief

**Goal.** Daily/weekly attention-reduced brief over operational data + sources. Reuse Scheduled Report machinery.

**Exit.** CEO receives Today / commitments / overdue / waiting / risks — not a dump.

---

## Phase 9 — Leadership Review

**Goal.** Observable meeting execution quality. No psychometrics.

**Depends on** Phase 5 (and preferably 6).

---

## Phase 10 — External integrations / dashboards

**Goal.** Additional APIs; **multiple mailboxes** bound to projects (superseding one-Google-account MVP where the CEO needs it).

LAVR still does not become CRM/ERP.

---

## Out of scope until explicitly scheduled

- Desktop (CANCELLED)
- Mobile companion / versioned Client API (deferred)
- SaaS / second CEO tenant
- Replacing Gmail/Jira/Slack
