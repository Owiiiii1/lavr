# Telegram Groups

> **CURRENT adapter.** Groups as Sources bound to Projects: [DATA_SOURCES.md](DATA_SOURCES.md), [PROJECTS.md](PROJECTS.md). Bot limitations apply.

**Status (M15):** IMPLEMENTED for discovery, raw persist, participants, owner Admin messenger, outbound, timezone, `project_groups` attach, **manual async Group Analysis**, and **owner-only `search_group_knowledge`** in personal Telegram/Web chat. **Not implemented:** mention/auto-reply, media blob download, automatic per-message analysis, proactive group alerts as a standalone watcher.

Scheduled Reports may include a `telegram_groups` source. That source summarizes **already stored** group messages for the Owner’s groups in the report window (`since_previous_report`, first run last 24h). It does not fetch live Telegram history the bot never received, and it does not expose internal group ids. Delivery of the report itself uses the personal Telegram DM adapter, not a group send.

Отдельный модуль Jarvis. Бот может состоять во многих Telegram-группах. Группы **не** создаются вручную в админке: факт подключения появляется из входящего Telegram update. ADR-011.

Telegram остаётся **channel adapter**. Модуль Groups живёт в Core (регистрация, persistence, политики, анализ) и в Admin Panel (просмотр и исходящие сообщения). Вызовы Bot API — только через Telegram Channel Adapter. ADR-015.

Личные DM любого Jarvis User и групповые чаты — **разные области**. ADR-012, ADR-056, ADR-057.

**Admin Groups и group knowledge — только Owner Space** (`telegram_groups`, `group_analysis`). Обычный user не видит группы и не получает group knowledge в personal prompt.

Каждая группа: `timezone` (IANA). Owner задаёт в Group Settings. Если пусто — **owner timezone**.

Group conversation: `conversations.user_id` остаётся NOT NULL, поэтому administrative owner = Jarvis owner. Граница — `kind=group` + `telegram_groups.conversation_id`. Personal retrieval/AI всегда фильтрует `kind=personal`. ADR-056.

Участники группы — `telegram_group_participants` (Telegram user id), **не** Jarvis `users`. Даже если numeric id совпадает с linked owner identity. ADR-059.

### Privacy mode (manual Telegram prerequisite)

Bots with Group Privacy ON receive only a subset of group messages (commands, mentions, replies to the bot). Full passive history requires the owner to disable privacy in BotFather (`/setprivacy` → Disable) and, if needed, grant the bot group admin/read rights. Cursor does not change BotFather settings. Until that is done, Jarvis can only persist updates Telegram actually sends. ADR-058.

Подробности памяти: [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md). Роли моделей: [AI_PROVIDER_ARCHITECTURE.md](AI_PROVIDER_ARCHITECTURE.md).

---

## Назначение

- Принимать и **сохранять** сообщения из групп (raw history).
- Показывать группы и читаемый чат в админке.
- Давать администратору отправить сообщение в группу от имени бота.
- По умолчанию **не отвечать** в группе (passive monitoring).
- Позже анализировать историю через **Analysis AI**, не смешивая результат с персональной памятью без явного provenance.

Это не второй ассистент и не CRM.

---

## Discovery и регистрация

Группы не вводятся руками (никакого обязательного «вставьте Group ID»).

### Условия

1. Бот добавлен в группу.
2. У бота достаточно прав, чтобы получать нужные апдейты (конкретный набор прав — `TBD`: как минимум чтение сообщений; для forum topics / voice / media — по мере поддержки типов).
3. Backend получает первое сообщение (или иной релевантный update) из ещё неизвестного `telegram_chat_id`.

### Алгоритм первого update

1. Adapter нормализует update и видит `chat.type` ∈ group / supergroup / forum (`TBD` точный набор; channels Telegram — отдельное решение, не смешивать молча).
2. Core ищет `telegram_groups` по `telegram_chat_id`.
3. Если нет — создаёт запись:
   - `telegram_chat_id`;
   - title (если есть);
   - Telegram chat type;
   - username / invite metadata, если доступны;
   - `first_seen_at`;
   - `status` (например `connected` / `restricted` / `left` — точный enum `TBD`);
   - пустые или дефолтные `settings` (режим: persist-only).
4. Создаёт (или привязывает) **group conversation** — отдельный тред, не personal DM.
5. Сохраняет raw message.
6. Группа сразу видна в админке.

Повторные апдейты той же группы обновляют `last_message_at`, title/username при изменении, счётчики. Не создают дубликат.

Source of truth факта «группа подключена» — входящий update, не форма в админке.

### Служебные события

`my_chat_member` / kick / смена прав: обновлять `status`, не удалять историю. Точный набор обрабатываемых service updates — `TBD`.

---

## Persistence сообщений

Все полученные из зарегистрированной группы сообщения сохраняются **до** аналитики. ADR-014.

Group conversations логически отделены от personal direct conversations. Технически используется **тот же message engine** (общая таблица `messages` + `conversations.kind`), чтобы не плодить второй пайплайн пагинации, вложений и идемпотентности. См. [DATABASE.md](DATABASE.md).

### Поля сообщения (концептуально)

Сохранять всё доступное, даже если UI на первом этапе показывает только текст:

- Telegram message ID;
- Telegram group / chat ID (через `telegram_groups` / conversation);
- sender Telegram user ID;
- sender name / username;
- reply-to (relation на другое raw message или external id);
- thread / forum topic ID, если Telegram отдаёт;
- text;
- message type;
- attachments metadata (не обязательно blob в MySQL);
- timestamp;
- edited status;
- raw Telegram metadata при необходимости (JSON), чтобы не потерять поля до расширения модели.

### Типы, которые модель не должна блокировать

- text;
- voice;
- photo;
- document;
- video;
- reply;
- forwarded;
- Telegram forum topics.

Первый этап реализации может писать и показывать только text (+ заглушка «unsupported type» для остальных). Схема и нормализация должны допускать расширение без смены engine.

Идемпотентность: уникальность `(channel, telegram_chat_id, telegram_message_id)` или эквивалент на `channel_message_id`.

Редактирование: обновлять raw + флаг edited, не плодить вторую «истину» без revision trail (`TBD` хранить предыдущий текст).

---

## Participants

Опциональная сущность `telegram_group_participants`:

- связь telegram user ↔ группа;
- display name / username на момент последнего сообщения;
- first/last seen;
- не обязательно маппить каждого участника на `users` ядра.

Владелец Jarvis — `users`. Участники группы — внешние identity. Путать их с personal `user_id` нельзя. Анализ «что обещал Иван» опирается на participant identity + текст, не на personal memory Ивана.

---

## Admin Panel

Раздел **Telegram Groups** (отдельный пункт админки, не вкладка «ввести ID»).

### Список

Для каждой автоматически найденной группы минимум:

- название;
- Telegram ID;
- статус;
- дата обнаружения;
- дата последнего сообщения;
- количество сохранённых сообщений;
- статус мониторинга / policy (`TBD`, на старте достаточно «persist only»).

Ручное создание группы не требуется. Ручное скрытие/архив — `TBD`, не путать с удалением raw.

### Страница группы — chat UI

Не лог событий. Обычный messenger:

- пузыри по хронологии;
- автор и время;
- визуально отличить участников и сообщения Jarvis (бот / исходящие из админки);
- пагинация или lazy loading;
- поддержка reply/thread в UI — по мере данных (`TBD` глубина первого экрана).

Администратор читает Telegram-диалог внутри админки.

### Исходящее сообщение

Поле ввода на странице группы.

```
Admin UI
  → Group Messaging Service (Core)
    → Telegram Channel Adapter
      → Telegram Bot API
        → группа
```

После успеха — то же сообщение в raw history группы (как исходящее от бота).

**Запрещено** вызывать Bot API из Inertia/React. ADR-015, ADR-009.

Ошибки API (нет прав, бот кикнут) — статус группе, сообщение в UI, без «тихого» успеха.

---

## Passive monitoring

Основной режим: **не отвечать** на каждое сообщение.

Путь группы:

```
Telegram group message
  → Telegram Adapter
  → normalize
  → persist raw message
  → optional lightweight processing
  → available for Analysis Engine
  → optional topic/memory extraction (не в personal memory автоматически)
  → no automatic response unless group policy requires it
```

Lightweight processing на первом этапе: обновить counters, participant, title. Без LLM в sync-пути группы.

Conversation Engine для **личных** DM по-прежнему отвечает. Групповой inbound **не** должен автоматически входить в personal reply path.

---

## Group policies (будущее)

Расширяемые настройки на `telegram_groups.settings`, не хардкод в адаптере:

- только сохранять (дефолт);
- отвечать при mention / reply на бота;
- разрешить активные ответы;
- periodic summaries (config exists, scheduler **off** by default);
- извлекать tasks / decisions / important events.

`analysis_enabled` and `daily_summary_enabled` default **false**. M14 runtime is **manual Admin (or CLI) run**, not analysis on every inbound group message. Analysis jobs read group raw; the Telegram adapter does not interpret policy as AI.

---

## Analysis (M14 runtime)

Зачем хранить группы: последующие вопросы владельца, например:

- что сегодня обсуждали в группе X;
- какие решения приняли;
- что про проект Jarvis;
- что обещал Иван;
- новые дедлайны;
- выжимка за неделю.

Делает **Owner Analysis AI**, не Owner Conversation AI и не Default User Conversation AI. Вся raw history групп хранится в нашей DB — это нормально. **Никогда** не слать весь archive одним prompt.

Derived types живут в `telegram_group_knowledge`, **не** в personal `memories`:

| Type | Смысл |
| --- | --- |
| Summary | что обсуждали (concise: topics, developments, unresolved questions) |
| Decision | явное agreement / решение, не «может» / вопрос |
| Task | кто что должен (`assignee_text` only; **not** a Reminder) |
| Event / Fact | заметное изменение состояния |

У каждой записи: `telegram_group_id`, provenance (`telegram_group_knowledge_sources`), confidence, status (`active` / `superseded` / `obsolete` / `disputed`), provider/model metadata. Без source messages derived fact не сохраняется.

User-facing ranges («сегодня», «вчера», custom dates) считаются в `telegram_groups.timezone` (fallback owner timezone) через `GroupTimeRangeService`. DB timestamps остаются UTC. DST учитывается Carbon/IANA.

### Hierarchical analysis

1. date range per group timezone;
2. retrieve bounded messages in range;
3. chunk (`config/group_analysis.php`: max messages/chars/chunks);
4. analyse each chunk (one structured JSON: summary + decisions + tasks + events);
5. if chunks > 1: reduce (dedupe/merge, union provenance, no invented facts);
6. persist final knowledge with dedupe/supersede.

Empty range: **no LLM call**; run `completed` with `metadata.no_data=true`.

Job: `AnalyzeTelegramGroupRangeJob` on queue `analysis`. Workers: systemd `jarvis-queue.service` and scheduler fallback `--queue=analysis,memory,default --timeout=180`. HTTP Admin returns immediately (`Analysis queued`). Idempotency: queued/processing run for the same group+range+mode is reused. Completed runs are not silently reprocessed; overlapping later runs reinforce or supersede knowledge instead of duplicating active facts. Left/missing groups fail terminal (`stale_source` / `missing_source`) without retry. Transient provider errors retry with backoff; `failed()` updates the domain row. Raw group archive is not deleted by analysis failure.

CLI: `php artisan jarvis:groups:analyze --group= --from= --to= --dry-run`. Operational retry (dry-run default): `jarvis:groups:retry-failed`. Diagnostics: `jarvis:reliability:report`. Do not `queue:retry all`.

### Owner personal chat → group knowledge

`ConversationContextBuilder` **не** подмешивает group knowledge или group raw в обычный DM, даже owner. Group data enters the turn only after an explicit tool call.

M14 indirect path: if a Telegram Group is attached to a Project, `get_project_context` may return **bounded ACTIVE derived** group knowledge (latest summaries + decisions/tasks/events). **Never raw group history.**

M15 dedicated path: owner Conversation AI calls `search_group_knowledge` (capability `group_analysis`). Channel-neutral `GroupKnowledgeSearchService`. Normal users do not receive the tool definition; forged execution is denied server-side.

Search is derived-first (ACTIVE `telegram_group_knowledge`), then bounded raw fallback. Missing or stale analysis may queue the existing M14 job; the tool does not wait. `today` / `yesterday` / custom dates are interpreted in each group's IANA timezone. Optional `project` limits the pool to attached groups. Participant name search uses `sender_name` / `sender_username` / `TelegramGroupParticipant.display_name` — never a Jarvis User map.

Limits live in `config/group_search.php`. No Vector DB. No personal memory writes. No Admin search UI.

---

## Связь с Memory Engine

История группы **не** становится автоматически personal memory. ADR-012.

Разделить области:

| Область | Примеры |
| --- | --- |
| Personal conversation history | DM владельца с Jarvis |
| Personal memory | факты о владельце, его решения в личке |
| Group conversation history | raw сообщений группы |
| Group knowledge | «в группе X решили сменить API» |

Каждая derived memory несёт provenance:

- source kind: `direct_conversation` / `telegram_group` / `summary` / `manual_admin` / иное;
- ссылки на group id и message ids;
- conversation id при необходимости.

Group knowledge можно **показать** Conversation model, если запрос владельца про эту группу. Нельзя молча влить в user profile.

Пересчёт group summaries не удаляет raw group messages. ADR-003, ADR-014.

---

## Permissions

- Недостаточные права бота: группа всё равно может появиться по первому увиденному update; `status` отражает ограничение.
- Бот покинул группу: history остаётся, inbound прекращается, outbound падает явно.
- Privacy Telegram (privacy policy бота, anonymous admins) — сохранять то, что API отдаёт; не выдумывать sender. `TBD` нюансы.

---

## Масштабирование

Много групп × высокая частота сообщений:

- persist в sync, analysis только async;
- не вызывать Analysis LLM на каждое групповое сообщение по умолчанию;
- индексы по `telegram_chat_id`, time, conversation_id (`TBD` при реализации);
- lazy load в админке;
- очередь analysis jobs — та же `TBD` инфраструктура, что в [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md).

Не вводить отдельный «group microservice» без нужды. Тот же Core, тот же adapter.

---

## Фазы

| Phase | Groups |
| --- | --- |
| 1 | Discovery, persist, админ-список и chat UI, outbound через adapter, passive, Owner Analysis AI в конфиге (может ещё не гоняться); group timezone |
| 2 | IMPLEMENTED M14+M15: analysis jobs, group knowledge + provenance, project-context bounded derived retrieval, owner DM `search_group_knowledge` |
| 3–5 | Same group data; Web/Admin remains the group UI. No Desktop client. Mobile not required. |

---

## Что не делать

- Не требовать ручной ввод Group ID как единственный способ регистрации.
- Не отвечать в группе по умолчанию.
- Не писать group history в personal facts без provenance.
- Не вызывать Telegram API из UI.
- Не кормить Analysis или Conversation всей лентой группы.
- Не делать отдельный LLM-стек внутри Telegram adapter.
- Не давать `role=user` доступ к group admin, group send или group knowledge в personal prompt.
