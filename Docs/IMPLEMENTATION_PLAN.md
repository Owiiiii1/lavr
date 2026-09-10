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
| LAVR Phase 3B | Telegram WebApp UX: Today/Chat/nav/theme/deep-link helpers; real Mini App E2E still blocked on empty bot settings |
| LAVR Phase 3C | Ukrainian-first localization: `uk` default, `en`/`ru` supported, UI locale ≠ assistant language, WebApp = Web |
| LAVR Phase 4 | People / Organizations / Projects as business contexts |
| LAVR Phase 5A | Meetings + manual transcript import |
| LAVR Phase 5B | Zoom cloud transcript ingest (live E2E NOT VALIDATED) |
| LAVR Phase 6 | First-class commitments + evidence + Meeting promotion |

Validation of origin flows: [CURRENT_STATE.md](CURRENT_STATE.md), [VALIDATION_CORE_WORKFLOW.md](VALIDATION_CORE_WORKFLOW.md).

---

## Sequence

Order matches dependency: a CEO-facing shell, then people/projects to hang facts on, then meetings → commitments → reliable automation → brief → review → extra APIs.

Existing `projects`, watchers, and scheduled reports are **reused and hardened**, not thrown away.

| Phase | Slice | Canonical docs |
| --- | --- | --- |
| **3A** | Telegram WebApp foundation (same Workspace, TG + browser) | [INTERFACES.md](INTERFACES.md) |
| **3B** | WebApp UX completion | [INTERFACES.md](INTERFACES.md), [Development/LAVR_PHASE_3B_REPORT.md](Development/LAVR_PHASE_3B_REPORT.md) |
| **3C** | Ukrainian-first localization (UI + assistant language) | [PRODUCT.md](PRODUCT.md#languages), [INTERFACES.md](INTERFACES.md), [ONBOARDING.md](ONBOARDING.md) |
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

Localization (**3C**) is a small cross-cutting slice **before / at the start of Phase 4**. Do not postpone it to Phase 12 polish: client-facing UI should be Ukrainian-first.

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

### Phase 3B — IMPLEMENTED (2026-09-09)

Code is in this repository. Report: [Development/LAVR_PHASE_3B_REPORT.md](Development/LAVR_PHASE_3B_REPORT.md). Same Workspace; no second frontend; no new domain tables.

| Item | Status |
| --- | --- |
| Today as CEO home from current tasks/reminders/notifications/reports + primary calendar read | IMPLEMENTED |
| Mobile nav / keyboard / safe-area / light-dark theme CSS | IMPLEMENTED / NOT VALIDATED on a real Telegram client |
| Notifications + Reports mobile surfaces from More | IMPLEMENTED |
| Allowlisted deep links + Open in LAVR on reminders and scheduled reports only | IMPLEMENTED |
| Menu Button command | Prepared; **not run** (no production bot token in MySQL) |
| Real Telegram client Mini App E2E | NOT VALIDATED |

**Stop condition:** production `telegram_bot_settings` is still empty and Owner Telegram identity is missing. Exact Owner steps are in the Phase 3B report. Do not create a second bot. Do not change webhook.

**Not in this phase.** People/Meetings/Commitments tables (Phase 4–6); Zoom; Executive Brief.

---

## Phase 3C — Ukrainian-first localization

**Goal.** One locale system for Telegram WebApp and standalone Web. Client-facing UI is Ukrainian-first before Phase 4 domain UI grows.

**CURRENT:** **IMPLEMENTED** for the Owner Workspace. Report: [Development/LAVR_PHASE_3C_REPORT.md](Development/LAVR_PHASE_3C_REPORT.md). Admin kit `en`/`ru` fragments are still not this product.

Shipped rules:

- default locale `uk`;
- supported locales: `uk`, `en`, `ru`;
- one canonical translation catalog; no second frontend;
- Telegram WebApp and standalone Web share the same locale;
- UI language and preferred assistant language are **separate** settings (both default `uk`);
- temporary reply-in-request-language does not silently change the stored preferred language;
- source artifacts stay in the original language ([DATA_SOURCES.md](DATA_SOURCES.md));
- names and original quotes are not auto-localized;
- translation fallback is Ukrainian;
- single-owner only — no multi-user locale architecture.

**Not in this phase.** People/Meetings/Commitments tables; Zoom; a duplicated Mini App frontend; full Admin translation.

**Exit.** Owner can switch UI language; assistant preferred language is independent; missing strings fall back to Ukrainian; Web and WebApp stay one app.

---

## Phase 4 — People / Organizations / Projects

**Status: IMPLEMENTED** (2026-09-09). Report: [Development/LAVR_PHASE_4_REPORT.md](Development/LAVR_PHASE_4_REPORT.md).

**Goal.** Unified `people` + roles + `employee_profiles` + `organizations` + relationships. Evolve `projects` into business contexts (mailbox/group bindings as far as Google MVP allows).

**Not in this phase.** Full multi-account Google (may stub bindings on the single connected account). Meeting intelligence.

**Exit.** «Кто такой Коля?» and project membership resolve from structured rows, with Admin merge/confirm.

---

## Phase 5A — Meetings + manual transcript import

**Goal.** First-class `meetings` with participants, project binding, **manual** transcript upload, original transcript storage, and Meeting Intelligence (topics, summary, decisions, tasks, open questions, risks, Leadership Review foundation). Commitments-as-drafts if Phase 6 is not done yet.

Manual upload is a **permanent** fallback (old Zoom meetings, non-CEO Zoom accounts, Google Meet, Teams, third-party transcripts, `.txt` / `.vtt` / later formats).

**Not in this phase.** Zoom OAuth, Zoom webhooks, automatic Zoom download.

**Exit.** An uploaded or pasted transcript becomes a Meeting with a stored original artifact, structured Meeting Intelligence, Workspace + Admin UI, and read tools. **IMPLEMENTED** 2026-09-09. Report: [Development/LAVR_PHASE_5A_REPORT.md](Development/LAVR_PHASE_5A_REPORT.md).

---

## Phase 5B — Zoom integration

**Goal.** Zoom meetings appear in LAVR automatically when the cloud transcript is ready. No CEO upload for meetings on the connected Zoom account.

Target: `recording.transcript_completed` webhook → validate → deduplicate → queue → `GET /meetings/{meetingId}/transcript` → download original → Meeting Intelligence. Webhook returns HTTP 200/204 immediately. [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md), [DATA_SOURCES.md](DATA_SOURCES.md#zoom).

**Not in this phase.** New People/Commitments tables (those are Phase 4 / 6). Do not hardcode an unconfirmed Zoom OAuth app type before checking current Zoom docs and the client account.

**Exit.** After a Zoom meeting on the connected account, a Meeting exists in LAVR with the original transcript, without a manual file upload. Duplicates are not created on webhook retry. **IMPLEMENTED** 2026-09-10 (mock tests). **LIVE ZOOM E2E: NOT VALIDATED.** Report: [Development/LAVR_PHASE_5B_REPORT.md](Development/LAVR_PHASE_5B_REPORT.md).

---

## Phase 6 — Commitments

**Goal.** First-class commitments, statuses, evidence, auto-extract from meetings/mail, distinct from Tasks.

**Exit.** «Что Коля обещал?» reads `commitments`, not only synthesis over knowledge events.

**IMPLEMENTED** 2026-09-10. Report: [Development/LAVR_PHASE_6_REPORT.md](Development/LAVR_PHASE_6_REPORT.md). Meeting promotion + manual create are CURRENT. Email/Telegram extraction pipelines and Owner live confirmation remain later / NOT VALIDATED. Do not restart this phase.

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

Ukrainian-first localization is **Phase 3C**, not this polish phase.

---

## Out of scope until explicitly scheduled

- Desktop (CANCELLED)
- Mobile companion / versioned Client API (deferred)
- SaaS / second CEO tenant
- Replacing Gmail/Jira/Slack
