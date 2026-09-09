# Interfaces

Canonical map of LAVR surfaces. Product: [PRODUCT.md](PRODUCT.md). Runtime: [CURRENT_STATE.md](CURRENT_STATE.md).

Three **user** interfaces share one backend / operational core. Admin is a fourth, **technical** surface.

```text
TELEGRAM CHAT  ─┐
TELEGRAM WEBAPP ─┼─► Conversation AI ─► Operational Core ─► Automation Engine ─► Sources
WEB APP        ─┘
ADMIN (technical)
```

Do not build a second frontend for Telegram WebApp. The same responsive LAVR Workspace should open as standalone browser and as Telegram WebApp.

---

## CURRENT

| Surface | Route / channel | Role today |
| --- | --- | --- |
| Telegram DM | Bot webhook `/telegram/webhook` | Fast chat, pairing via `access_code`, optional voice in/out |
| Telegram Groups | Same bot | Source / analysis; not the CEO’s personal UI |
| Standalone Web Workspace | `/lavr` | Full chat + Task / Reminder / Watcher / Report / Notification centers + Overview + Voice |
| Telegram WebApp | `/telegram/webapp` | Same Workspace after HMAC session; Mini App E2E NOT VALIDATED (needs token + pairing) |
| Admin | `/dashboard`, `/settings/*`, owner resources (`/projects`, `/telegram-groups`, …) | Technical management |
| Legacy paths | `GET /jarvis`, `GET /chat` | Redirect to `/lavr` |

There is a Telegram WebApp **foundation** (Phase 3A) plus **UX completion** (Phase 3B): `/telegram/webapp` validates Mini App `initData` and opens the same Workspace (Today, Chat, nav, Notifications, Reports). Real Telegram-client E2E is **NOT VALIDATED**. Details: [Development/LAVR_PHASE_3A_REPORT.md](Development/LAVR_PHASE_3A_REPORT.md), [Development/LAVR_PHASE_3B_REPORT.md](Development/LAVR_PHASE_3B_REPORT.md).

Owner UI language switch and preferred assistant language are **TARGET**. WebApp and standalone Web must share one catalog when that ships. Do not treat Admin `en`/`ru` fragments as the product locale. [PRODUCT.md](PRODUCT.md#languages).

Today’s docs that call Web Workspace “PRIMARY” describe **current** shipping UI. Target primary **rich** UI is Telegram WebApp using that same Workspace. Target primary **fast** channel is Telegram Chat. See ADR-267, ADR-268, ADR-269.

---

## 1. Telegram Chat — primary fast channel

**Target and largely current** as the CEO’s quick path.

Used for:

- ordinary conversation;
- short questions;
- voice messages and voice replies;
- notifications, reminders, alerts;
- short daily summaries;
- quick approve / reject;
- quick follow-up commands.

Examples: «Лавр, что сегодня главное?», «Что Коля обещал сделать?», «Есть что-то критичное по Chicago?», voice → voice.

Telegram Chat is **not** the only UI and must not become the only UI.

Adapter rules (unchanged): Telegram does not own AI logic. Normalize inbound → Conversation Engine / automation delivery → render outbound. [CHANNELS.md](CHANNELS.md) (implementation detail). [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md).

Bot / group limitations: [DATA_SOURCES.md](DATA_SOURCES.md#telegram).

---

## 2. Telegram WebApp — primary rich UI (Phase 3B UX CURRENT; Mini App E2E NOT VALIDATED)

Intended analogue of a mobile app inside Telegram.

**Phase 3A CURRENT:** Mini App entry `/telegram/webapp`, server-side HMAC validation of `initData`, Owner-only session, shared Workspace (`/lavr/*`) with mobile bottom nav and Today. People / Meetings / Commitments are honest placeholders (no new tables).

**Phase 3B CURRENT:** Today/Chat/nav/theme/keyboard/safe-area UX; Notifications and Reports from More; allowlisted deep links; Open in LAVR on reminder and scheduled-report Telegram messages only. Menu Button artisan command is ready and **not run** until the existing bot token is in MySQL.

**Still TARGET / NOT VALIDATED:** operational People/Projects/Meetings/Commitments UI from later phases; Menu Button and real-client E2E until the existing bot token + Owner pairing are present.

Preferred architecture (unchanged): do **not** create a separate frontend. Reuse the responsive LAVR Workspace (`resources/js/personal-workspace/…`) so it opens:

- standalone browser (`https://lavr.youngfashionshow.com/lavr`);
- Telegram WebApp.

Target sections (product UX, not a current route map):

| Section | Purpose |
| --- | --- |
| Chat | Same conversations as Telegram Chat / Web |
| Today | Attention-reduced day view |
| People | [PEOPLE_AND_RELATIONSHIPS.md](PEOPLE_AND_RELATIONSHIPS.md) |
| Projects | [PROJECTS.md](PROJECTS.md) |
| Meetings | [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md) — list/detail; **import status** (manual vs Zoom); source; unresolved project/people |
| Commitments | [COMMITMENTS.md](COMMITMENTS.md) |
| Tasks | Formal tasks — [TASKS.md](TASKS.md) |
| Waiting For | What the CEO is waiting on |
| Decisions | [DOMAIN_MODEL.md](DOMAIN_MODEL.md#decisions) |
| Reports | Scheduled reports + Executive Brief |
| Knowledge | Documents / index — [KNOWLEDGE_LAYER.md](KNOWLEDGE_LAYER.md) |
| Notifications | Inbox |
| Settings | Assistant, voice, integrations, policies; **UI language** and **preferred assistant language** (separate; default `uk`) |

Phase 3A–3B in [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) is the WebApp foundation and UX completion, not the full domain UI. Live Mini App on a phone waits on existing-bot token + pairing: [Development/LAVR_PHASE_3B_REPORT.md](Development/LAVR_PHASE_3B_REPORT.md).

---

## 3. Standalone web

**CURRENT and TARGET:** `https://lavr.youngfashionshow.com`

Same core as Telegram. Same frontend as Telegram WebApp (target). Login, `/lavr` workspace, voice modalities (Рация / Диалог Beta).

Telegram WebApp and standalone Web **share one locale system** and one translation catalog. Do not duplicate the frontend or ship a second set of UI strings. Changing UI language in Settings applies to both shells.

---

## 4. Admin — technical, not CEO daily UI

The CEO must not be required to use Admin for normal work.

Admin remains necessary for:

- manual data correction;
- People, Organizations, Projects, Meetings, Commitments, Tasks (when those tables exist);
- confirming Meeting project binding and unresolved Zoom/manual participants;
- Sources, Integrations, Watchers, Scheduled Reports;
- system settings, diagnostics, AI prompts/configuration;
- confirming difficult identity / relationship links.

Admin ≠ conversation channel. [PRODUCT.md](PRODUCT.md).

---

## Interaction rules

- One conversation catalog for Telegram DM and Web (already true).
- WebApp and standalone Web are the same app in two shells (target).
- One UI locale for Telegram WebApp and standalone Web (`uk` default; `en` and `ru` supported). The Owner switches it manually. There is no per-user locale product beyond this single Owner.
- Preferred assistant language is independent of UI locale ([PRODUCT.md](PRODUCT.md#languages), [ONBOARDING.md](ONBOARDING.md)).
- Notifications may land in Telegram Chat even when the CEO is in WebApp.
- Mutating external actions still require confirmation policy. [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md).
