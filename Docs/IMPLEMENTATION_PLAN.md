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
| **3A** | Telegram WebApp foundation (same Workspace, TG + browser) | [INTERFACES.md](INTERFACES.md) |
| **3B** | WebApp UX completion | [INTERFACES.md](INTERFACES.md), [Development/LAVR_PHASE_3A_REPORT.md](Development/LAVR_PHASE_3A_REPORT.md) |
| **4** | People / Organizations / Projects as business contexts | [PEOPLE_AND_RELATIONSHIPS.md](PEOPLE_AND_RELATIONSHIPS.md), [PROJECTS.md](PROJECTS.md) |
| **5A** | Meetings + manual transcript import | [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md) |
| **5B** | Zoom integration (automatic transcript import) | [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md), [DATA_SOURCES.md](DATA_SOURCES.md) |
| **6** | Commitment extraction + tracking | [COMMITMENTS.md](COMMITMENTS.md) |
| **7** | Automation Engine hardening | [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md), [EVENT_MODEL.md](EVENT_MODEL.md) |
| **8** | Executive Brief | [EXECUTIVE_BRIEF.md](EXECUTIVE_BRIEF.md) |
| **9** | Leadership Review | [LEADERSHIP_REVIEW.md](LEADERSHIP_REVIEW.md) |
| **10** | Multi-source business integration | [DATA_SOURCES.md](DATA_SOURCES.md) |
| **11** | Proactive operational control | [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md), [COMMITMENTS.md](COMMITMENTS.md) |
| **12** | Production polish | [CURRENT_STATE.md](CURRENT_STATE.md) |

Phase 7 can start **in parallel** with 5–6 for report/watcher validation (already burning in production). Full event bus waits for operational entities.

Onboarding business map ([ONBOARDING.md](ONBOARDING.md)) spans 3–8; do not block Phase 3B on it.

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

### Phase 3B — WebApp UX completion

**Goal.** Finish the Mini App as a daily CEO surface: real Telegram-client E2E, Menu Button, remaining shell/UX gaps from the Phase 3A report. Same Workspace; no second frontend.

**Not in this phase.** People/Meetings/Commitments tables (Phase 4–6); Zoom.

---

## Phase 4 — People / Organizations / Projects

**Goal.** Unified `people` + roles + `employee_profiles` + `organizations` + relationships. Evolve `projects` into business contexts (mailbox/group bindings as far as Google MVP allows).

**Not in this phase.** Full multi-account Google (may stub bindings on the single connected account). Meeting intelligence.

**Exit.** «Кто такой Коля?» and project membership resolve from structured rows, with Admin merge/confirm.

---

## Phase 5A — Meetings + manual transcript import

**Goal.** First-class `meetings` with participants, project binding, **manual** transcript upload, original transcript storage, and Meeting Intelligence (topics, summary, decisions, tasks, open questions, risks, Leadership Review foundation). Commitments-as-drafts if Phase 6 is not done yet.

Manual upload is a **permanent** fallback (old Zoom meetings, non-CEO Zoom accounts, Google Meet, Teams, third-party transcripts, `.txt` / `.vtt` / later formats).

**Not in this phase.** Zoom OAuth, Zoom webhooks, automatic Zoom download.

**Exit.** An uploaded transcript becomes a Meeting with a stored original artifact, not only a Knowledge document.

---

## Phase 5B — Zoom integration

**Goal.** Zoom meetings appear in LAVR automatically when the cloud transcript is ready. No CEO upload for meetings on the connected Zoom account.

Target: `recording.transcript_completed` webhook → validate → deduplicate → queue → `GET /meetings/{meetingId}/transcript` → download original → Meeting Intelligence. Webhook returns HTTP 200/204 immediately. [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md), [DATA_SOURCES.md](DATA_SOURCES.md#zoom).

**Not in this phase.** New People/Commitments tables (those are Phase 4 / 6). Do not hardcode an unconfirmed Zoom OAuth app type before checking current Zoom docs and the client account.

**Exit.** After a Zoom meeting on the connected account, a Meeting exists in LAVR with the original transcript, without a manual file upload. Duplicates are not created on webhook retry.

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

**Depends on** Phase 5A (and preferably 6). Zoom-sourced meetings from 5B should feed the same review.

---

## Phase 10 — Multi-source business integration

**Goal.** Additional APIs; **multiple mailboxes** bound to projects (superseding one-Google-account MVP where the CEO needs it). Conferencing beyond Zoom remains upload/fallback unless a later slice names a provider.

LAVR still does not become CRM/ERP.

---

## Phase 11 — Proactive operational control

**Goal.** Once People, Meetings, Commitments, and the Automation Engine exist, LAVR puts agreements on control without a special CEO command, and notifies only when attention is due.

**Depends on** Phases 4–7 (and 5B for automatic Zoom-sourced commitments).

---

## Phase 12 — Production polish

**Goal.** Harden what already ships: validation, UX, reliability, Owner-confirmed campaigns. Not a new domain model.

---

## Out of scope until explicitly scheduled

- Desktop (CANCELLED)
- Mobile companion / versioned Client API (deferred)
- SaaS / second CEO tenant
- Replacing Gmail/Jira/Slack
