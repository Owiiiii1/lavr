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
| Admin | `/dashboard`, `/settings/*`, owner resources (`/projects`, `/telegram-groups`, …) | Technical management |
| Legacy paths | `GET /jarvis`, `GET /chat` | Redirect to `/lavr` |

There is **no** Telegram WebApp in code.

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

## 2. Telegram WebApp — primary rich UI (TARGET)

Intended analogue of a mobile app inside Telegram.

**Not implemented.** No `WebApp` / `initData` integration in this repository.

Preferred architecture: do **not** create a separate frontend. Reuse the responsive LAVR Workspace (`resources/js/personal-workspace/…`) so it opens:

- standalone browser (`https://lavr.youngfashionshow.com/lavr`);
- Telegram WebApp.

Target sections (product UX, not a current route map):

| Section | Purpose |
| --- | --- |
| Chat | Same conversations as Telegram Chat / Web |
| Today | Attention-reduced day view |
| People | [PEOPLE_AND_RELATIONSHIPS.md](PEOPLE_AND_RELATIONSHIPS.md) |
| Projects | [PROJECTS.md](PROJECTS.md) |
| Meetings | [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md) |
| Commitments | [COMMITMENTS.md](COMMITMENTS.md) |
| Tasks | Formal tasks — [TASKS.md](TASKS.md) |
| Waiting For | What the CEO is waiting on |
| Decisions | [DOMAIN_MODEL.md](DOMAIN_MODEL.md#decisions) |
| Reports | Scheduled reports + Executive Brief |
| Knowledge | Documents / index — [KNOWLEDGE_LAYER.md](KNOWLEDGE_LAYER.md) |
| Notifications | Inbox |
| Settings | Assistant, voice, integrations, policies |

Phase 3 in [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) is the WebApp foundation (auth, shell, shared Workspace), not the full domain UI.

---

## 3. Standalone web

**CURRENT and TARGET:** `https://lavr.youngfashionshow.com`

Same core as Telegram. Same frontend as Telegram WebApp (target). Login, `/lavr` workspace, voice modalities (Рация / Диалог Beta).

---

## 4. Admin — technical, not CEO daily UI

The CEO must not be required to use Admin for normal work.

Admin remains necessary for:

- manual data correction;
- People, Organizations, Projects, Meetings, Commitments, Tasks (when those tables exist);
- Sources, Integrations, Watchers, Scheduled Reports;
- system settings, diagnostics, AI prompts/configuration;
- confirming difficult identity / relationship links.

Admin ≠ conversation channel. [PRODUCT.md](PRODUCT.md).

---

## Interaction rules

- One conversation catalog for Telegram DM and Web (already true).
- WebApp and standalone Web are the same app in two shells (target).
- Notifications may land in Telegram Chat even when the CEO is in WebApp.
- Mutating external actions still require confirmation policy. [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md).
