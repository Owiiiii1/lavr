# LAVR — проект

LAVR создан на основе JARVIS как **отдельный production instance для одного клиента**. Это не SaaS и не публичная мультиюзерная система. Регистрация сторонних пользователей не предусмотрена. UI и бизнес-логика — single-user / single-client. [LAVR_MIGRATION.md](LAVR_MIGRATION.md).

## Назначение

LAVR — персональный AI-ассистент. Один собеседник на инстанс, с долговременной памятью между его чатами и каналами.

**Основной interactive client — Web Personal Workspace** (`/lavr`). Legacy `/jarvis` и `/chat` редиректят на `/lavr`. Telegram — вторичный адаптер. Voice — модальность Web, не отдельный продукт. Mobile — возможный будущий companion. **Desktop отменён.**

Разговор, начатый в Telegram, доступен в Web **этого же** клиента.

LAVR должен:

- общаться текстом и голосом (Voice **MANUAL PASS** на Web)
- помнить историю
- накапливать персональный контекст
- выбирать релевантный контекст (Memory + Context Budget)
- пассивно слушать Telegram-группы (Owner)
- держать один личный контекст клиента (не Owner Space vs User Space)
- reminders как Core-объект

## Что LAVR не является

- Не набор изолированных чатов с обнуляемым контекстом.
- Не Telegram-бот с AI-логикой внутри адаптера.
- Не отдельный «голосовой ассистент» рядом с текстовым.
- Не Desktop/Tauri-приложение.
- Не админка: Admin Panel — техника. Общение — Personal Workspace.
- Не один «мозг на весь инстанс».

## Принцип одного ядра

Существует один **assistant core**. Клиенты — адаптеры:

- Telegram (implemented);
- Personal Workspace `/lavr` (PRIMARY);
- Voice mode over Web (MANUAL PASS);
- Mobile companion (DEFERRED);
- Desktop — **CANCELLED**.

Ядро владеет разговорами, памятью, оркестрацией AI и сборкой контекста. Канал только доставляет нормализованное сообщение и возвращает ответ.

Единственный рабочий аккаунт клиента — запись `users` (обычно `role=owner`). Telegram pairing: уникальный `access_code` (зарезервированный owner-код **`2000`**). Код не web-пароль и не создаёт User из чата.

Мультиюзерная модель JARVIS (ordinary users, `/chat`, Admin Users, impersonation) **снята с продукта** в Phase 1.

Telegram-группы — отдельный модуль. [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

AI-конфигурация: **Owner Conversation AI**, **Owner Analysis AI**, **Default User Conversation AI**.

Integrations (Google Calendar/Gmail, GitHub, ElevenLabs TTS, Web Research) — **owner-only** кроме user capabilities (voice/storage/research). [INTEGRATIONS.md](INTEGRATIONS.md). Start: [Docs/README.md](README.md).

## Этапы

Исторические четыре фазы: [DEVELOPMENT_PHASES.md](DEVELOPMENT_PHASES.md). Актуальные: [ROADMAP.md](ROADMAP.md) A–E.

## Архитектурные принципы

1. Один центральный assistant core.
2. Channels не содержат AI-логики.
3. База данных — source of truth для conversations и memory.
4. Raw data отделена от AI-generated interpretations.
5. AI-generated memory может быть пересчитана.
6. Смена LLM provider не должна ломать ядро.
7. Контекст собирается динамически под запрос.
8. Вся история не передаётся модели без необходимости.
9. Ядро не мешает появлению tools/actions, но они не цель Phase 1.
10. Voice — другой интерфейс к тому же Jarvis.
11. Один `user_id` = единый **личный** memory context на всех его устройствах и чатах; не между разными users.
12. История Telegram-групп — отдельная область; в personal memory только с явным provenance.
13. Owner Conversation AI, Owner Analysis AI и Default User Conversation AI — разные configuration domains.
14. User General Prompt правит сам user; не отменяет platform/security. Optional later: per-user model override поверх Default User Conversation AI.
15. Cross-chat: summary-first / raw-on-demand. Telegram выбирает active conversation.
16. Reminders — Core, не Calendar; **today** Telegram-gated create/delivery; **target** channel-independent.
17. Projects ≠ Topics; group knowledge — только explicit owner tool.
18. Capabilities поверх roles, не россыпь `if role === owner`.
19. Сначала простая рабочая система, затем усложнение memory intelligence.
20. Не over-engineer текущий Core ради будущих фаз B–E, но не hardcode owner по `user_id` и не смешивать access_code с паролем.
21. Исполняемый порядок — [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md), не абстрактные фазы в одиночку.

## Текущее состояние репозитория

Production Laravel app: Core, Admin, Web Personal Workspace, Telegram adapter, Voice, Memory, Storage, integrations. Snapshot: [CURRENT_STATE.md](CURRENT_STATE.md). Direction: [ROADMAP.md](ROADMAP.md).

Документы в `Docs/` описывают целевую архитектуру **и** текущий runtime. Начните с [README.md](README.md) и [CURRENT_STATE.md](CURRENT_STATE.md).

## Связанные документы

- [README.md](README.md) — индекс
- [ARCHITECTURE.md](ARCHITECTURE.md)
- [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md)
- [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md)
- [CHANNELS.md](CHANNELS.md)
- [CLIENTS/WEB_WORKSPACE.md](CLIENTS/WEB_WORKSPACE.md)
- [CLIENTS/CLIENT_API.md](CLIENTS/CLIENT_API.md) — deferred
- [DECISIONS.md](DECISIONS.md)
- [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md)
- [AI_PROVIDER_ARCHITECTURE.md](AI_PROVIDER_ARCHITECTURE.md)
- [DATABASE.md](DATABASE.md)
- [ROADMAP.md](ROADMAP.md)
- [USERS_AND_CABINET.md](USERS_AND_CABINET.md)
- [REMINDERS.md](REMINDERS.md)
- [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md)
- [PROJECTS.md](PROJECTS.md)
- [INTEGRATIONS.md](INTEGRATIONS.md)
- [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md)
- [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md)
- [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md) — Telegram DM voice inbound (STT) and `sendVoice` delivery
- [CURRENT_STATE.md](CURRENT_STATE.md) — только факт кода
