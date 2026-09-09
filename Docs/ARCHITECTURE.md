# Архитектура

> **CURRENT vs TARGET.** This file describes **module boundaries of the running system**. Product vision and operational core: [PRODUCT.md](PRODUCT.md), [DOMAIN_MODEL.md](DOMAIN_MODEL.md), [INTERFACES.md](INTERFACES.md). Next phases: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md). Runtime: [CURRENT_STATE.md](CURRENT_STATE.md).

Source of truth for what exists: production code + Owner-confirmed validation.

**TARGET interfaces (not all shipped):** Telegram Chat (fast) · Telegram WebApp + Web (same Workspace) · Admin (technical). Diagram: [DOMAIN_MODEL.md](DOMAIN_MODEL.md#target-conceptual-schema).

```
                    LAVR Core
                         |
     +-------------------+-------------------+
     |                   |                 |
     |                   |                 |
 Web Personal         Telegram         Integrations
 Workspace            adapter           / tools
 (CURRENT rich UI)    (DM + Groups)
  /lavr
 WebApp               TARGET Mini App
 (TARGET = same UI)
     |
     +-- Text
     +-- Voice (modality)
     |
     +-- Storage / attachments
     +-- Memory / Context Budget
     +-- Reminders (Core; Telegram + Web Push adapters)
     +-- Tasks (Core; separate from reminders)
     +-- Notification Center (in-app inbox; reuses Web Push transport)
     +-- Projects (work container; TARGET = business context)
     +-- Knowledge Layer (index; not People/Meetings/Commitments tables)
     |
 TARGET (not current)
     +-- Telegram WebApp (same Workspace)
     +-- Operational Core (People, Meetings, Commitments, Decisions, Events)
     +-- Automation Engine hardening / Executive Brief
     +-- Mobile companion (deferred)

Desktop client: CANCELLED. Not a node in this architecture.
```

---

## Primary interactive application

**CURRENT:** Web Personal Workspace (`/lavr`) is the shipped rich UI. Telegram DM is the fast channel.

**TARGET:** [INTERFACES.md](INTERFACES.md) — Telegram Chat (fast), Telegram WebApp (primary rich UI, same frontend), standalone Web remains. Admin is not the CEO daily UI.

| Surface | Route | Role |
| --- | --- | --- |
| Personal Workspace | `/lavr` | the single client talks to LAVR |
| Admin Panel | `/dashboard` | Technical management, not chat |
| `/jarvis`, `/chat`, `/cabinet` | compatibility redirects | not the product name |

Voice is a **modality** of that workspace over an existing `conversation_id`. It is not a separate client or assistant.

Telegram Chat is the **CURRENT (and TARGET) fast channel**. Same Conversation Engine, same catalog. Optional voice inbound (STT) and voice **delivery** (`sendVoice`) of canonical assistant text — not a second assistant. Default Telegram replies remain text. [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md). [INTERFACES.md](INTERFACES.md).

Mobile is a **future optional companion**. Same Core. Not required for Phase A/B.

---

## Core Backend

Единственное место оркестрации ответа.

Отвечает за:

- Owner Space / User Spaces; capabilities поверх role
- users: role, access_code, timezone, status
- per-user assistant profile (`user_assistant_profiles`)
- channel identities (Telegram pairing; no auto-create User)
- conversations / messages (`kind` direct \| group; personal always `user_id`)
- message_attachments (ephemeral by default)
- stored_files (persistent Storage per `user_id`; Storage **page** owner-only)
- voice_sessions (modality; no `voice_messages` / `voice_memory`)
- Web Research tools via provider abstraction
- ContextBudgetManager
- Telegram Groups (owner-only admin)
- Tool / Integration Layer (Owner: Google, GitHub, ElevenLabs TTS config, Web Research)
- Memory and topics
- AI: Owner Conversation / Owner Analysis / Default User Conversation
- Reminder Engine (channel-independent; Telegram + Web Push adapters)
- Task Engine + Notification Center + opt-in briefs / bounded proactive
- authorization / ownership

Не отвечает за:

- парсинг Telegram update
- Orb shaders
- конкретный HTTP SDK провайдера
- захват микрофона на клиенте

---

## AI Layer

Ядро: «собери контекст и получи ответ». AI Layer не знает, Telegram это или Web.

| Компонент | Роль |
| --- | --- |
| LLM Provider abstraction | chat/complete |
| Prompt management | role platform prompt + User General Prompt + assistant identity |
| Context builder | hierarchy + ContextBudgetManager |
| Topic classifier / Memory retriever / extractor / summarizer | Memory Engine |
| Tool/function calling | Integration Framework |
| Response generator | финальный ответ |

STT/TTS — Voice runtime, не второй мозг. Conversation AI не вызывается из `VoiceRuntimeService` напрямую: только `ConversationTurnService`.

---

## Clients

| Client | Status |
| --- | --- |
| Web Personal Workspace | CURRENT rich UI, IMPLEMENTED |
| Telegram Chat | CURRENT fast channel, IMPLEMENTED |
| Telegram WebApp | TARGET (Phase 3; same Workspace) |
| Voice UI (Orb + session) | IMPLEMENTED, MANUAL PASS (Web) |
| Mobile | DEFERRED companion |
| Desktop | CANCELLED |
| Versioned Client API | DEFERRED until a non-Web client needs it |

Web uses Laravel/Inertia session routes. That is not a second engine.

---

## Future modules (not in Core today)

- Tasks (distinct from Reminders)
- Notification Center / Web Push
- Proactive Engine (event/condition driven)
- Personal Knowledge Graph
- People / Contacts intelligence

See [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md).
