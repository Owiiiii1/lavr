# Users, workspaces, and Telegram pairing

> **LAVR Phase 1.** This document describes the **JARVIS origin** multi-user model (Owner vs ordinary users, `/jarvis` vs `/chat`, Admin Users, impersonation). That product surface is **removed**. Current LAVR: one client, workspace `/lavr`, no third-party accounts. See [LAVR_MIGRATION.md](LAVR_MIGRATION.md). Kept as historical origin.

«Cabinet» is **legacy wording**. Canonical for LAVR:

- Personal Workspace = `/lavr`
- `/jarvis` and `/chat` = compatibility redirects
- `/cabinet` = compatibility redirects only

Два **логических пространства** на общих engines. Role задаёт default **capability set**, не второй Conversation Engine и не второй chat frontend.

- **Owner Space** — полностью изолированный AI-контекст владельца + admin/integrations/groups/projects.
- **User Space** — полностью независимое пространство каждого `role=user`.

User A, User B и Owner personal context **никогда** не смешиваются. Изоляция: `user_id` / scope / configuration domain / capabilities.

Связано: [CHANNELS.md](CHANNELS.md), [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md), [REMINDERS.md](REMINDERS.md), [PROJECTS.md](PROJECTS.md), [INTEGRATIONS.md](INTEGRATIONS.md), [USER_ADMINISTRATION.md](USER_ADMINISTRATION.md), ADR-016–045, ADR-184–200, ADR-209–219.

Фактический код (M25U.3): Owner создаёт users; саморегистрации нет. Каталог Users + User Card (`/settings/users/{user}`), включая onboarding status / assistant name. Status `active`/`disabled`. Impersonation session-scoped. Один Shared Personal Workspace (`resources/js/personal-workspace`). Owner `/jarvis`, user `/chat`. `/cabinet` — compatibility. User на admin route получает 403. Web chat и Telegram используют один catalog и `ConversationTurnService`. Owner login → `/jarvis`. User login → `/chat`. Owner `/chat` → `/jarvis`. User `/jarvis` → `/chat`. Assistant profile (`user_assistant_profiles`) задаёт имя/характер/стиль; General Prompt — workspace settings drawer (`user_ai_settings`); Memory — факты со временем. Reminders panel — GET/cancel (LIVE BUG: Owner не видит панель в user workspace). Onboarding не блокирует чат. Подробно: [USER_ADMINISTRATION.md](USER_ADMINISTRATION.md), [ASSISTANT_PERSONALIZATION.md](ASSISTANT_PERSONALIZATION.md).

Owner подтвердил: создание пользователя, login, `/chat`, базовые запросы → **MANUAL PASS**. Voice Web → **MANUAL PASS**. Onboarding entry → **MANUAL PARTIAL**. Reminders panel / create-without-Telegram — не PASS. A/B IDOR campaign не выполнен.

---

## Roles

Минимум два значения. Сложный RBAC-пакет не обязателен. Расширяемый enum / колонка `users.role`.

| Role | Кто | Web после login | Telegram | Admin / integrations |
| --- | --- | --- | --- | --- |
| `owner` | один главный владелец инстанса | **Personal Workspace** `/jarvis` (общение) + **Admin Panel** `/dashboard` (техника) | тот же pairing, код `2000` | `*` |
| `user` | все остальные в каталоге Users | **Personal Workspace** `/chat` (`/cabinet` redirects here) | pairing своим `access_code` | нет |

Ровно один owner на инстанс. Не `if ($userId === 1)`.

## Spaces

### Owner Space

conversations; conversation summaries; personal memories; **projects**; topics; General Prompt; **Owner Conversation AI** + **Owner Analysis AI**; reminders (Telegram-gated today); Telegram DM; Telegram Groups + group knowledge; integrations; Gmail; Calendar; **Voice**; tools.

### User Space

conversations; summaries; personal memory; Assistant profile + optional conversational onboarding; General Prompt; **Default User Conversation AI**; Telegram DM; Web Personal Workspace (`/chat`); reminders (chat create + workspace panel); timezone; images/files in chat; instance Web Research; Voice Runtime/UI.

Нет: Admin, Groups, Projects, Google, GitHub, owner AI config, Storage page, integration settings.

Engines общие: Conversation, Context Builder, Telegram Adapter, Reminder Engine, AI Provider Layer.

---

## Capabilities

Не размазывать `if role === owner` по всему коду. Role → default set. Проверка в Core.

| Capability | owner | user (M25U.1 / M25U.2) |
| --- | --- | --- |
| chat | да | да |
| memory | да | да |
| telegram_dm | да | да |
| reminders | да | да |
| cabinet / personal_workspace / profile | да | да |
| web_research | да | да (instance provider) |
| voice | да | да |
| storage (read/search tools; chat files) | да | да |
| storage page / delete_storage_file | да | нет |
| projects | да | нет |
| telegram_groups | да | нет |
| group_analysis | да | нет |
| gmail | да | нет |
| google_calendar | да | нет |
| github | да | нет |
| integrations_admin | да | нет |
| users_admin | да | нет |
| impersonation | да | нет |
| system_ai_settings | да | нет |

Owner = все. Расширение permissions без нового engine.

---

### Owner (`*`)

Исключительный доступ ко всему инстансу:

- Admin Panel, Users, User Cards, чужие Chats / Topics;
- AI Settings (platform + чужие user settings);
- Telegram Settings, Telegram Groups, group chats, outbound в группы;
- Integrations (Google Calendar, Gmail, ElevenLabs, позже другие);
- system settings, diagnostics, logs;
- impersonation;
- все будущие tools/actions.

Owner **также** обычный участник Conversation Core: своя history, topics, memories, Telegram DM, User General Prompt. Основной web-чат owner — `/jarvis` (Personal Workspace). Admin остаётся технической панелью. ADR-086, ADR-110.

### User

Пока **только**:

- Web Personal Workspace `/chat`: login, chats, composer, images/files, push-to-talk Voice, General Prompt, profile name/timezone, personal assistant voice, own password change;
- Telegram DM after pairing + Chat Selector;
- reminders (create currently Telegram-gated; target: Core independent of Telegram);
- instance Web Research tools (`search_web`, `fetch_web_page`);
- own Storage files via chat + read/search tools (no Storage page).

**Не** имеет (backend 403 / deny, не только скрытое меню):

- Admin Panel, Settings, Users, Telegram Groups;
- чужие chats / memory / topics;
- integrations, Gmail, Google Calendar;
- system AI settings, logs, admin APIs.

Все query scoped by `user_id`. ADR-021, ADR-030.

---

## Access code

Не web-пароль. Не email. Не database id. Код **первичной привязки внешнего канала** (сейчас Telegram).

| Правило | Деталь |
| --- | --- |
| Уникальность | DB unique + генерация с retry при коллизии |
| Человекочитаемый | короткий набор символов; точный алфавит `TBD` (цифры допустимы) |
| Owner | зарезервирован **`2000`**. Не переиспользовать. Не считать web password |
| Обычный user | генерируется при создании записи |
| Видимость | owner видит код на User Card; может regenerate. Regenerating does **not** unlink the current Telegram identity |
| После pairing | код для последующих Telegram-сообщений не спрашивается |
| Web Workspace | вход email/login + password. Access code **не** секрет workspace |

Неверный / неизвестный код: не создавать User, не вызывать AI.

---

## Web authentication split

Один `User` model, один guard допустим. После login:

```
if role === owner → /jarvis (Personal Workspace); Admin at /dashboard
if role === user  → /chat (Personal Workspace)
```

`/cabinet` and `/cabinet/chats/{id}` redirect to `/chat` (owner `/cabinet` → `/jarvis`).

User на admin route: **403**. Owner `/chat` → `/jarvis`. User `/jarvis` → `/chat`. Owner login → `/jarvis`. User login → `/chat` (no `intended()` to Admin). Disabled login uses generic `auth.failed`.

Impersonation: только owner, session-scoped, без пароля цели. Пока impersonation активен, Auth = target user (нет Admin). Exit → `/jarvis`. ADR-209–219. [USER_ADMINISTRATION.md](USER_ADMINISTRATION.md).

---

## Admin: Users — каталог Jarvis

Не «admin accounts». Таблица всех людей инстанса.

Колонки:

- name;
- email / login;
- role (`owner` / `user`);
- access_code;
- status;
- Telegram linked yes/no;
- chats count;
- messages count;
- last activity;
- created_at.

Строка → User Card. Только owner.

---

## User Card (owner only)

- profile, role, status;
- access_code + **Regenerate Code**;
- Telegram linked + **Unlink Telegram** (+ later relink);
- password set / reset (hash only; plaintext никогда);
- Chats (read/debug);
- Topics;
- AI Settings (User General Prompt on the same `user_ai_settings` row);
- Open as User (impersonation). No prominent hard-delete control.

Disable is preferred over delete. Ordinary user эту страницу не видит.

---

## Personal Workspace (`role=user`)

Минимум: **Chat**, плюс редактирование **своего General Prompt**. Timezone используется для отображения времени сообщений. В Workspace settings каждый пользователь выбирает собственный TTS voice из шести curated ElevenLabs voices; выбор хранится в `users.voice_id` и не меняет голоса других пользователей.

Chat: sidebar список, Новый чат, history, composer, attachments, Voice. Canonical URL `/chat/chats/{id}`. `/cabinet/chats/{id}` redirects here. Тот же каталог conversations и те же messages, что в Telegram. Engine: `ConversationTurnService`. Frontend: тот же `PersonalWorkspace`, что Owner `/jarvis`.

New Chat: title `Новый чат`, пустой raw. AI: **Default User Conversation AI**, не Owner Conversation AI.

---

## Telegram pairing

Транспорт: **webhook** + Nutgram. Long polling не нужен. Pairing and DM reply path are implemented (M2+).

Адаптер **не** вызывает LLM. Web Workspace и Telegram DM вызывают `ConversationTurnService`. Неверный код и `/start` без pairing — системные ответы, не Conversation AI.

### Один Telegram identity

`(channel=telegram, external_user_id)` уникален. Нельзя привязать один Telegram account к двум Jarvis Users одновременно.

На MVP также действует обратное ограничение: **один Jarvis User — одна Telegram identity**. Второй Telegram account к тому же User не привязывается; переподключение только через owner unlink. ADR-046.

Owner может unlink и regenerate access code. **Regenerate не снимает текущую Telegram-привязку.** Unlink удаляет только identity этого user; чаты остаются. Disabled linked user: системный ответ, без Conversation AI.

### `/start` — identity нет

Системный текст (смысл, формулировка `TBD`):

«Привет. Для доступа к Jarvis нужен код авторизации. Введите код, полученный от владельца.»

AI не вызывается. User не создаётся.

### Текст без pairing

Любое подходящее текстовое сообщение = попытка access code.

```
Telegram update
  → identify Telegram user
  → lookup channel_identities
  → absent
  → parse access_code
  → find active User by access_code
```

Код не найден:

«Код не найден. Проверьте код или обратитесь к владельцу Jarvis.»

Не создавать User. Не звать AI. Не писать это в normal conversation history. Auth/security event — можно.

### Код найден — pairing

Создать `channel_identities`:

- channel = `telegram`;
- `external_user_id`;
- username, first/last name если есть;
- `user_id`;
- `linked_at`;
- `last_seen_at`;
- `active_conversation_id` (тот же `user_id`).

Дальше Telegram ID — авторизованный канал. Код не спрашивать.

**Первый pairing UX:** создать conversation **`Основной`**, сделать active, записать AI greeting туда. Каталог тот же, что Web Workspace.

Приветствие — Conversation AI **пространства** (Owner vs Default User config). Не только «Вы авторизованы».

### Telegram Chat Selector

Telegram ≠ один вечный conversation. Меню / commands / buttons **«Чаты»**:

- список conversations этого user;
- выбрать существующий;
- создать новый;
- показать текущий.

После выбора: `Выбран чат «<name>».` Дальнейшие DM → `channel_identities.active_conversation_id`.

New Chat из Telegram = обычная `conversation`, видна в Web Workspace.

Active conversation **обязана** принадлежать тому же `user_id`. Хранение: **`channel_identities.active_conversation_id`** (один Telegram identity = одно active). Чище, чем отдельная session, пока один бот-чат на identity.

### Авторизованный DM

```
Webhook → Adapter → identity → User → active conversation
  → persist → Context Builder (space AI) → persist → send
```

Tools/groups — capabilities, не адаптер.

**Current:** paired DM inbound is text or a voice note (existing Gemini STT → transcript). Outbound: `sendMessage` by default; `sendVoice` when response mode is `voice`, or `auto` with voice inbound. [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md).

### Повторный `/start` при уже связанной identity

Код не спрашивать. Предпочтительно Conversation AI (или короткое системное + AI).

### Owner Telegram

Тот же механизм. Первичный код **`2000`** → связь с `role=owner`. Отдельного owner-бота нет.

---

## Groups и integrations

Telegram Groups в админке — **только owner**. Обычный user не видит группы, не получает group knowledge в personal prompt, не шлёт в группы.

Google Calendar / Gmail / ElevenLabs — **только owner**, через Integration / Tool Layer. Не вшивать в Telegram adapter. [INTEGRATIONS.md](INTEGRATIONS.md).

---

## Memory scopes

- personal memory каждого User (включая owner) — M12 runtime;
- Telegram group knowledge (owner/analysis) — M14 runtime (`telegram_group_knowledge`, capability `group_analysis`); not personal memory;
- system/global при необходимости.

Workspace не имеет отдельного Memory UI: memory работает в фоне. Owner diagnostics: Settings → Users → Memory (read-only). Cross-user retrieval запрещён.

---

## Что не делать

- Не называть access_code паролем кабинета.
- Не создавать User из неизвестного Telegram.
- Не пускать неверный код в AI.
- Не давать `role=user` admin routes «потому что залогинен».
- Не disable / demote sole Owner через ordinary User management.
- Не hardcode owner по `id=1`.
- Не строить второй Conversation Engine для owner.
- Не резолвить Owner Conversation AI для `role=user`.
- Не считать Telegram одним вечным чатом.
