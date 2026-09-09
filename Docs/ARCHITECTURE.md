# Архитектура

Source of truth is production code plus Owner-confirmed validation. Phases: [ROADMAP.md](ROADMAP.md).

```
                    LAVR Core
                         |
     +-------------------+-------------------+
     |                   |                 |
     |                   |                 |
 Web Personal         Telegram         Integrations
 Workspace            adapter           / tools
 (PRIMARY)            (DM + Groups)
  /lavr
     |
     +-- Text
     +-- Voice (modality)
     |
     +-- Storage / attachments
     +-- Memory / Context Budget
     +-- Reminders (Core; Telegram + Web Push adapters)
     +-- Tasks (Core; separate from reminders)
     +-- Notification Center (in-app inbox; reuses Web Push transport)
     +-- Projects (Owner)
     |
 future (not current)
     +-- Mobile companion
     +-- Knowledge Graph

Desktop client: CANCELLED. Not a node in this architecture.
```

---

## Primary interactive application

**Web Personal Workspace** is the product UI.

| Surface | Route | Role |
| --- | --- | --- |
| Personal Workspace | `/lavr` | the single client talks to LAVR |
| Admin Panel | `/dashboard` | Technical management, not chat |
| `/jarvis`, `/chat`, `/cabinet` | compatibility redirects | not the product name |

Voice is a **modality** of that workspace over an existing `conversation_id`. It is not a separate client or assistant.

Telegram is a **secondary messaging channel / adapter**. Same Conversation Engine, same catalog. Optional voice inbound (STT) and voice **delivery** (`sendVoice`) of canonical assistant text — not a second assistant. Default Telegram replies remain text. [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md).

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
| Web Personal Workspace | PRIMARY, IMPLEMENTED |
| Telegram adapter | IMPLEMENTED |
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
