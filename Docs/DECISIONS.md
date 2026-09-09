# Architectural Decision Log

Краткие ADR. Новые решения добавлять сюда, не размазывать по чатам.

Статус: **Accepted** — действующие принципы проекта. Изменение требует новой записи, не молчаливой правки старой.

**LAVR product ADRs start at ADR-266.** ADR-001–265 include JARVIS origin (multi-user, Web-as-primary, Desktop, etc.). Where they conflict with ADR-266+, **LAVR ADRs win** for this repository. Product: [PRODUCT.md](PRODUCT.md).

---

## ADR-001 — Jarvis Core независим от communication channels

**Контекст.** Ассистент появится в Telegram, затем в приложениях и голосе.

**Решение.** Вся оркестрация (user, conversation, memory, AI) живёт в Core. Канал не принимает решений модели.

**Следствие.** Новый канал = новый adapter, не форк ядра.

---

## ADR-002 — Telegram есть первый adapter, не ядро

**Контекст.** Phase 1 = Telegram MVP.

**Решение.** Telegram-код только receive / normalize / send. Nutgram/webhook не вызывают LLM.

**Следствие.** Нельзя «для скорости» положить prompt в Telegram handler. Это блокирует Phase 3.

---

## ADR-003 — Полная raw history независимо от derived memory

**Контекст.** Summaries и facts будут ошибаться и пересчитываться.

**Решение.** Messages сохраняются всегда. Derived layer можно стереть и построить заново.

**Следствие.** Запрещено удалять raw «потому что уже есть summary».

---

## ADR-004 — Phase 2: selective retrieval, не вся история в модели

**Контекст.** Длинный личный контекст не влезает в prompt.

**Решение.** Context package: prompt + working context + релевантные topics/memories + нужные recent messages. Не вся БД.

**Следствие.** Phase 1 recent-window — допустимое упрощение того же интерфейса retriever.

---

## ADR-005 — Relational DB как основное хранилище; Vector DB не обязательна

**Контекст.** Semantic search полезен, но не нужен, чтобы запуститься.

**Решение.** MySQL/relational — source of truth. Embeddings и Vector DB — optional later (`TBD` момент).

**Следствие.** Phase 2 можно начать на SQL + topics. Не ставить Vector DB в prerequisites MVP.

---

## ADR-006 — Mobile и Desktop используют единый backend и единую память

**Контекст.** Иначе появятся три ассистента.

**Решение.** Приложения и Cabinet — клиенты API того же Core. Нет локального memory engine. Речь о **личной** памяти **текущего** `user_id`. Group knowledge не подмешивается автоматически.

**Следствие.** Чаты этого user видны в Web Workspace и его Telegram DM. Чужой user их не видит. Группы — отдельная область. **Desktop CANCELLED (ADR-235).** Mobile deferred (ADR-237).

---

## ADR-007 — AI providers абстрагированы от business logic

**Контекст.** Провайдеры и модели сменятся.

**Решение.** AI Layer + роли моделей. Core не зависит от одного SDK.

**Следствие.** Админка меняет mapping роль → модель без миграции каналов.

---

## ADR-008 — Voice layer абстрагирован от speech provider

**Контекст.** Предпочтительный TTS/STT — ElevenLabs, рынок сменится.

**Решение.** Порты STT/TTS. ElevenLabs — реализация по умолчанию, не домен.

**Следствие.** Смена vendor не трогает Conversation Engine.

---

## ADR-009 — Admin Panel = конфигурация и диагностика, не источник AI-логики

**Контекст.** Удобно вызвать модель «из настроек».

**Решение.** Админка пишет settings/prompts и показывает логи/чаты. Ответ пользователю всегда через Core.

**Следствие.** Platform prompt принадлежит выбранному conversation config (Owner vs Default User), не «один prompt на весь инстанс». User General Prompt — отдельный слой на user (ADR-018). Analysis — отдельный prompt. Нет скрытой логики и нет прямых вызовов Telegram/LLM из Inertia. Уточнение: ADR-013, ADR-015, ADR-020, ADR-034.

---

## ADR-010 — Phase 4 = conversational intelligence layer, не только prompt engineering

**Контекст.** Естественность требует latency, turn-taking, retrieval, barge-in.

**Решение.** Phase 4 наращивает слой над Core/Memory/Voice, а не заменяет архитектуру одним текстом system prompt.

**Следствие.** Нельзя отложить retrieval и channel-independence «потому что в Phase 4 поправим промптом».

---

## ADR-011 — Telegram Groups регистрируются по первому update

**Контекст.** Бот может быть во многих группах. Ручной ввод Group ID не масштабируется и расходится с реальностью Telegram.

**Решение.** Группы не создаются формой в админке. При первом inbound update из неизвестного `telegram_chat_id` Core создаёт `telegram_groups` (metadata, `first_seen_at`, status) и group conversation. Source of truth подключения — входящий update.

**Следствие.** Админка показывает автообнаруженные группы. Нет обязательного «вставьте ID».

---

## ADR-012 — Group conversations и personal memory — разные области

**Контекст.** Рабочая группа порождает решения и задачи, которые не являются фактами о владельце.

**Решение.** Group history и group knowledge отделены от personal conversation history и personal memory. Memory Engine хранит scope и provenance. История группы не становится personal fact автоматически.

**Следствие.** Analysis пишет в `telegram_group_knowledge` (M14), never into personal `memories`. Conversation package does not auto-mix group knowledge. M14 may surface bounded derived facts via `get_project_context` when a group is attached to a project. M15 owner DM/Cabinet calls `search_group_knowledge` explicitly.

---

## ADR-013 — Role-based AI configuration

**Контекст.** Общение и анализ — разные нагрузка, цена и промпты. Один vendor на всё — ложная экономия архитектуры.

**Решение.** Независимые configuration domains. Уточнение ADR-034 / ADR-035: минимум **Owner Conversation AI**, **Owner Analysis AI**, **Default User Conversation AI**. Каждая: provider, model, credentials reference, prompt, parameters. Не обязаны совпадать. Позже слоты classification / summarization / embeddings без смены business logic.

**Следствие.** Запрещена архитектура «одна глобальная модель Jarvis» и «одна Conversation AI на owner и users». Админка конфигурирует domains раздельно.

---

## ADR-014 — Group messages сохраняются как raw до анализа

**Контекст.** Summaries и extract по группам будут ошибаться и пересчитываться.

**Решение.** Все увиденные сообщения зарегистрированной группы пишутся в raw history (тот же message engine, `kind=group`) до любой аналитики. Analysis не заменяет persist.

**Следствие.** Нельзя удалять group raw «потому что есть выжимка». Не слать всю ленту модели на каждый запрос.

---

## ADR-015 — Исходящие в группу из админки идут через Telegram Channel Adapter

**Контекст.** Удобно вызвать Bot API из UI или отдельного «admin telegram client».

**Решение.** `Admin UI` → Group Messaging Service → **тот же** Telegram Channel Adapter → Bot API. После успеха — persist в group history. UI не вызывает Telegram API.

**Следствие.** Один путь outbound для бота. Согласовано с ADR-002 и ADR-009.

---

## ADR-016 — Изоляция user context

**Контекст.** Несколько людей на одном backend и одном боте.

**Решение.** У каждого user независимые profile, conversations, messages, topics, memories, summaries, AI settings, cabinet access. Retrieval всегда scoped by `user_id` (или явный иной owner scope). Context user A не попадает к user B.

**Следствие.** Общий provider/модель/бот не означают общую память. Глобальный unscoped memory search запрещён.

---

## ADR-017 — New Chat не создаёт новую long-term memory

**Контекст.** Несколько независимых чатов в Cabinet.

**Решение.** Новая conversation имеет пустую raw history. Long-term memory принадлежит user и может использоваться во всех его чатах, если retrieval признал факт релевантным. Сырые сообщения других чатов не копируются.

**Следствие.** `New Chat ≠ New User Memory`. Обнуление чата ≠ обнуление профиля/memories.

---

## ADR-018 — User General Prompt поверх platform rules

**Контекст.** Ребёнку нужен другой стиль, чем владельцу. Platform safety и изоляция общие.

**Решение.** User General Prompt — отдельный слой. Hierarchy: platform/system → channel rules → user prompt → memory → topics → conversation history → current message. User prompt не отменяет критические platform rules.

**Следствие.** Не хранить «один personality prompt на весь инстанс» как единственную личность всех users.

---

## ADR-019 — Platform defaults и optional per-user AI override

**Контекст.** Не у каждого user свой vendor.

**Решение.** Default User Conversation AI — отдельный platform default, **не** наследует Owner Conversation AI. Optional later: per-user model override **поверх Default User Conversation AI**. `resolveConversationAI(user)`.

**Следствие.** Пустой override = Default User Conversation AI. User никогда не получает Owner Conversation config «по умолчанию».

---

## ADR-020 — Admin открывает Cabinet через impersonation

**Контекст.** Диагностика кабинета. Пароль user нельзя показывать.

**Решение.** Open Cabinet создаёт impersonated session. UI показывает режим, есть выход, действие логируется. Пароль не передаётся и не отображается.

**Следствие.** Запрещён «войти, подсмотрев пароль». Запись в чат от имени user — не часть этого ADR.

---

## ADR-021 — User resources защищены ownership layer

**Контекст.** URL с id легко перебрать.

**Решение.** Все user endpoints проверяют, что ресурс принадлежит текущему user (или это явный privileged admin action). Policies / authorization в Core, не только UI.

**Следствие.** Залогиненный user B не читает `/conversations/123` user A.

---

## ADR-022 — Граница ролей owner и user

**Контекст.** Сейчас любой `users` row — полный админ.

**Решение.** Две роли: `owner` (`*`) и `user` (cabinet + свой Telegram DM). Один owner на инстанс. Не hardcode `user_id`. User не получает Admin Panel, Settings, Groups, integrations, чужие данные. Enforcement в backend.

**Следствие.** Login owner → admin; login user → cabinet.

---

## ADR-023 — Owner access code `2000`

**Контекст.** Нужен человекочитаемый Telegram pairing для владельца.

**Решение.** Зарезервированный уникальный `access_code=2000` у owner. Это **не** web-пароль.

**Следствие.** Генератор обычных кодов не выдаёт `2000`.

---

## ADR-024 — Уникальные access codes

**Контекст.** Pairing не должен использовать email или id.

**Решение.** У каждого User уникальный human-readable `access_code`. Unique constraint + retry в приложении. Owner видит код и может regenerate.

**Следствие.** Коллизии на уровне БД невозможны.

---

## ADR-025 — Telegram pairing через access code

**Контекст.** Webhook ACK-only; бот молчит. Нужна авторизация канала.

**Решение.** Несвязанный Telegram: `/start` просит код; текст = попытка кода. Успех → identity. Неуспех → отказ. AI не вызывается до успеха.

**Следствие.** Транспорт webhook + Nutgram.

---

## ADR-026 — channel_identity после pairing

**Контекст.** Нужна устойчивая связь Telegram ↔ User.

**Решение.** Строка `channel_identities`: telegram, external_user_id unique, names, user_id, linked_at. Дальше сообщения резолвят User без кода.

**Следствие.** Один Telegram id — один User. Обратно: один User — одна Telegram identity на MVP (ADR-046).

---

## ADR-046 — Один Telegram identity на Jarvis User (MVP)

**Контекст.** User мог бы иметь несколько Telegram accounts; это усложняет pairing и admin UX на Phase 1.

**Решение.** На MVP у каждого `users` row не более одной `channel_identities` записи с `channel=telegram`. Вторая попытка pairing для того же User отклоняется. Переподключение другого Telegram account — только после owner unlink.

**Следствие.** Pairing service проверяет существующую identity User до создания новой. Regenerate access code не unlink-ит Telegram автоматически.

---

## ADR-027 — Telegram не создаёт User

**Контекст.** Удобно завести аккаунт с первого `/start`.

**Решение.** Запрещено. User создаёт только owner (каталог). Неизвестный код не регистрирует человека.

**Следствие.** Нет открытой саморегистрации через бота.

---

## ADR-028 — Integrations только owner

**Контекст.** Gmail/Calendar/ElevenLabs на инстансе.

**Решение.** Tool/Integration Layer доступен `role=owner`. User не видит Integrations и не получает эти tools.

**Следствие.** Groups admin тоже owner-only (согласовано с ADR-012).

---

## ADR-029 — Google через Tool Layer

**Контекст.** Можно вызвать Calendar API из Telegram handler.

**Решение.** Google Calendar и Gmail — adapters Tool Layer. Conversation AI делает tool calls. Не вшивать Google в Nutgram/Telegram adapter.

**Следствие.** Тот же tool доступен из cabinet/API owner, не только из Telegram.

---

## ADR-030 — Строгий ownership

**Контекст.** User и owner на одном API.

**Решение.** Все personal resources scoped `user_id`. Owner читает чужое только через явные admin endpoints (User Card / impersonation), не через cabinet API жертвы без audit.

**Следствие.** Усиливает ADR-021.

---

## ADR-031 — Owner — обычный User в Conversation Core

**Контекст.** Иначе появится второй AI pipeline.

**Решение.** Owner имеет personal history, topics, memories, Telegram DM, Conversation AI как любой User, плюс administrative role.

**Следствие.** Pairing `2000` — тот же механизм, что чужой код.

---

## ADR-032 — Owner Space и User Spaces изолированы

**Контекст.** Различия только через `role` недостаточно: легко смешать context.

**Решение.** Логические пространства. Owner Space и каждый User Space полностью изолированы: conversations, summaries, memory, General Prompt, Telegram DM, reminders, timezone. Owner personal context не смешивается с User contexts. User A не видит User B.

**Следствие.** Isolation = scope / ownership / configuration, не второй продукт.

---

## ADR-033 — Общие engines, раздельные scopes

**Контекст.** Иначе появится N реализаций Conversation Engine.

**Решение.** Общие технические engines: Conversation, Context Builder, Telegram Adapter, Reminder Engine, AI Provider Layer. Различие — `user_id`, capabilities, AI config domain.

**Следствие.** Новый permission не создаёт новый engine.

---

## ADR-034 — Owner Conversation AI ≠ Default User Conversation AI

**Контекст.** Owner может держать дорогую модель; users не должны её наследовать.

**Решение.** Два независимых conversation configs. User не резолвит Owner Conversation AI. Optional later: per-user override поверх Default User Conversation AI.

**Следствие.** Уточняет ADR-013 и ADR-019.

---

## ADR-035 — Owner Analysis AI отдельный

**Контекст.** Groups, summaries, extract, project analysis — другая нагрузка.

**Решение.** Owner Analysis AI — отдельный provider/model/prompt. Не обслуживает обычный user DM. Не обязан совпадать с Owner Conversation AI.

**Следствие.** Jobs и DM не делят один «активный» model switch.

---

## ADR-036 — Cross-chat: summary-first / raw-on-demand

**Контекст.** Нельзя считать, что каждый chat знает raw всех остальных.

**Решение.** В пакет: current raw/recent + relevant summaries других chats того же user + structured memory. Raw другого чата — только targeted retrieval. Cross-user retrieval запрещён.

**Следствие.** New Chat пустой по raw, не «амнезия профиля».

---

## ADR-037 — Telegram выбирает active conversation

**Контекст.** Telegram ≠ один вечный conversation. Cabinet уже предполагает каталог.

**Решение.** Один каталог conversations на space. `channel_identities.active_conversation_id` (тот же `user_id`). Меню «Чаты»: выбрать / создать / текущий. Первый pairing: conversation `Основной`, active, greeting туда.

**Следствие.** Web и Telegram видят одни и те же chats.

---

## ADR-038 — Reminders — Core subsystem, не Google Calendar

**Контекст.** Легко отдать «напомни» в Calendar API.

**Решение.** Reminder Engine — своя сущность и pipeline: Conversation AI → Reminder Tool → Engine → DB → worker → Telegram. Calendar — другой owner tool.

**Следствие.** «Напомни завтра» ≠ calendar event. Доступно owner и users.

---

## ADR-039 — Reminder delivery сейчас Telegram-only

**Контекст.** Нет product-ready web/mobile push.

**Status.** CURRENT CODE still Telegram-only delivery. **TARGET SUPERSEDED by ADR-240 / ADR-241.**

**Следствие.** Cabinet может создать reminder текстом, но канал доставки — бот.

---

## ADR-040 — Timezone per user и per group

**Контекст.** «Завтра в 11» и «сегодня в группе» без TZ бессмысленны.

**Решение.** `users.timezone` IANA для relative dates и reminders (`run_at` UTC). `telegram_groups.timezone` для group date queries; fallback → owner timezone.

**Следствие.** Не интерпретировать «сегодня» в TZ сервера.

---

## ADR-041 — Project ≠ Topic

**Контекст.** Тема классификации ≠ рабочий контейнер.

**Решение.** Projects — сущность Owner Space. Relations к conversations/topics/groups/memories/group knowledge, без копирования raw. Users на MVP не получают Projects.

**Следствие.** Topic `Jarvis` и project `JARVIS` — разные вещи.

---

## ADR-042 — Group knowledge в Owner AI только через explicit tool

**Контекст.** Auto-merge всех групп в owner prompt сломает личный контекст и окно модели.

**Решение.** Group knowledge не personal memory. Owner Conversation AI ходит в stored derived, then bounded raw, через explicit `search_group_knowledge` (capability `group_analysis`). Access scope is `ToolExecutionContext.user`, never model arguments. Missing/stale analysis may queue M14; the personal turn does not wait.

**Следствие.** «Что решили в группе 1?» — tool. Молчаливого подмешивания нет. Normal user не видит definition и не может forged-execute.

---

## ADR-043 — Hierarchical analysis больших group histories

**Контекст.** Вся raw history групп в нашей DB — нормально. Один prompt на archive — нет.

**Решение.** Date range per group TZ → retrieve → chunk → analyse → aggregate per group → reduce across groups. Jobs/queue. Не зависеть от одного context window.

**Следствие.** Analysis AI не обязан «видеть всё сразу».

---

## ADR-044 — Tool loop: несколько calls в одном turn

**Контекст.** «Свободен завтра и поставь встречу» = free/busy + create.

**Решение.** Conversation Engine поддерживает последовательные tool calls в одном turn. Не `one message = max one tool call`. Read-only обычно без confirm; явная команда авторизует write; самопредложенный write — confirm; destructive — повышенный confirm.

**Следствие.** Multi-step Google/Gmail/reminders/group search — один user message.

---

## ADR-045 — Capability layer поверх roles

**Контекст.** Десятки `if role === owner` размажут политику.

**Решение.** Capabilities (`chat`, `memory`, `telegram_dm`, `reminders`, `projects`, `telegram_groups`, `group_analysis`, `gmail`, `google_calendar`, `integrations_admin`, `users_admin`, `voice`, `impersonation`). Role задаёт default set. Проверка в Core. Owner = все; user сейчас = chat/memory/telegram_dm/reminders/cabinet.

**Следствие.** Новые permissions без нового Conversation Engine.

---

## ADR-046 — Reminder без Telegram identity не создаётся

**Status.** HISTORICAL / SUPERSEDED as product target by ADR-240. **CURRENT CODE still enforces this.**

**Контекст.** Delivery сейчас только Telegram. Можно было бы сохранить scheduled reminder до pairing.

**Решение.** Если у User нет linked Telegram identity, reminder **не создаётся**. Сообщение: «Для получения напоминаний сначала подключите Telegram.» Не копить undeliverable rows.

**Следствие.** Tool result `telegram_not_connected`. Web Workspace тоже требует Telegram pairing для create until M25U.3.1.

---

## ADR-047 — Current local time инжектится каждый turn

**Контекст.** Модель должна понимать «завтра», «через два часа» без hardcoded даты.

**Решение.** На каждом conversation turn в system context: current user local datetime (ISO с offset) и `users.timezone`. Не hardcode.

**Следствие.** Tool получает structured `run_at_local`; Core применяет IANA timezone (DST). Offset, противоречащий IANA, игнорируется в пользу IANA.

---

## ADR-048 — One-time reminders; recurrence later

**Контекст.** Schema имеет `recurrence_rule`. Пользователь может сказать «каждый день в 9».

**Решение.** Recurrence не реализуется в этом milestone. AI сообщает, что повторяющиеся напоминания не поддерживаются. Не создавать one-time reminder как подмену recurring.

**Следствие.** `create_reminder` — единственный reminder tool сейчас. list/cancel later.

---

## ADR-049 — Derived memory is async

**Контекст.** Extraction/summarization на Owner Analysis AI не должны увеличивать Telegram/Web latency.

**Решение.** После persist inbound → Conversation AI → persist assistant → ответ пользователю. Затем `AnalyzeConversationTurnJob` / `UpdateConversationSummaryJob` на queue `memory`. Conversation turn не ждёт Analysis AI.

**Следствие.** Structured memory может появиться с небольшой задержкой после реплики.

---

## ADR-050 — Relational retrieval first

**Контекст.** Vector DB улучшает semantic search, но не нужна, чтобы изолировать users и не раздувать prompt.

**Решение.** M12 retrieval — MySQL: `user_id` first, keyword/`normalized_key`/topic, confidence, freshness, validity. Нет Pinecone/Qdrant/Weaviate/pgvector.

**Следствие.** Embeddings — later, когда relational quality станет узким местом.

---

## ADR-051 — Raw-on-demand via Core tool

**Контекст.** Summary-first недостаточно, если пользователь спрашивает деталь старого чата.

**Решение.** Tool `search_conversation_history`. Модель не передаёт `user_id`. Core использует `ToolExecutionContext.user`. Bounded snippets. Чужие conversations игнорируются.

**Следствие.** Chat A не получает raw Chat B автоматически.

---

## ADR-052 — Analysis AI processes derived memory; scope stays user_id

**Контекст.** Один Analysis config проще, чем отдельная модель на каждого user.

**Решение.** Owner Analysis AI — background engine для extract/summary любых users. `user_id` всегда задаёт Core. Model-generated user_id игнорируется. Derived rows User A никогда не читаются в retrieval User B.

**Следствие.** Не нужен отдельный User Analysis AI в M12.

---

## ADR-053 — Queue worker via systemd

**Контекст.** Database queue без worker не обрабатывает memory jobs. Telegram уже имеет crontab flock worker на queue `telegram`.

**Решение.** `jarvis-queue.service` (`queue:work database --queue=analysis,memory,default --timeout=180`). Telegram worker crontab не дублируется этим unit. Reminder scheduler cron не трогается. Supervisor не ставится. Scheduler fallback uses the same queue list and `--timeout=180` so job timeouts are not killed at the worker default of 60s.

**Следствие.** Memory, group analysis, attachment summary, and default-queue jobs обрабатывает systemd; Telegram updates — отдельный worker. `retry_after` (300s) остаётся выше worker timeout.

---

## ADR-054 — Project context is tool-driven

**Контекст.** Все owner projects в каждый prompt раздуют окно и смешают несвязанную работу.

**Решение.** Projects не входят в `ConversationContextBuilder` по умолчанию. Owner Conversation AI вызывает `get_project_context` по релевантному запросу. Capability `projects` только у owner.

**Следствие.** «Привет» не получает JARVIS/YFS/RTS.

---

## ADR-055 — No project_groups until Groups subsystem exists

**Статус.** Superseded by ADR-056 / M11.

**Контекст.** Telegram Groups ещё не были реализованы в M13.

**Решение (M13).** Не создавать `project_groups`.

**Следствие.** M11 создал `project_groups` после появления Groups subsystem.

---

## ADR-056 — Group conversation administrative owner vs personal boundary

**Контекст.** `conversations.user_id` NOT NULL. Делать его nullable в M11 рискованно для personal DM.

**Решение.** Group conversation использует owner `user_id` как administrative owner. Обязательная граница: `kind=group` + `telegram_groups.conversation_id`. Cabinet, Telegram chat selector, Conversation AI, PersonalMemoryRetriever, history search и memory jobs фильтруют `kind=personal`.

**Следствие.** `user_id=owner` на group conversation не делает её personal chat.

---

## ADR-057 — Group inbound never enters personal Conversation AI

**Контекст.** Group text can look like a personal prompt («Jarvis, привет»).

**Решение.** `chat.type` group/supergroup → Groups subsystem only: persist, participants, counters. No `ConversationTurnService`, no Conversation AI, no `AnalyzeConversationTurnJob`, no personal topics/memories. Mentions do not change this in M11.

**Следствие.** Routing is by Telegram chat type, not by linked ChannelIdentity.

---

## ADR-058 — Group Privacy mode is a manual Telegram prerequisite

**Контекст.** With privacy ON, Telegram does not send most group messages to the bot.

**Решение.** Jarvis persists every group update it receives. Full-history monitoring requires the owner to disable BotFather Group Privacy and grant needed group rights. Cursor never changes BotFather settings or claims full monitoring without Telegram delivering updates.

**Следствие.** Empty Admin history can mean Telegram never sent the messages.

---

## ADR-059 — Group participant is not a Jarvis User

**Контекст.** Numeric Telegram user id may match a linked owner identity.

**Решение.** `telegram_group_participants` has no FK to `users`. Participant rows exist only for a real Telegram `from` user. `sender_chat` / anonymous admin stores sender metadata on the message only.

**Следствие.** Group analysis attributes text to participants / display names, not personal memory of a Jarvis User. M14 writes `telegram_group_knowledge` only.

---

## ADR-060 — Group knowledge is a separate table, not personal memories

**Контекст.** Personal Memory Engine already uses `memories` with `scope=personal` + `user_id`. Reusing that table for Telegram groups would mix owner facts with group chat extract, including when the owner authored a group message.

**Решение.** M14 stores derived group facts in `telegram_group_knowledge` keyed by `telegram_group_id`. Provenance is `telegram_group_knowledge_sources`. Runs are `telegram_group_analysis_runs`. Analysis is manual/async Owner Analysis AI (queue `analysis`). Hierarchical chunk/reduce; structured JSON validation; dedupe via `normalized_key`; supersede via status + revisions. Group tasks are not Reminders. `analysis_enabled` / `daily_summary_enabled` default false. M15 reads this layer via `search_group_knowledge`; it does not write personal `memories`.

**Следствие.** PersonalMemoryRetriever and ConversationContextBuilder ignore group knowledge. Project context may include bounded ACTIVE derived rows only. Owner group questions use `search_group_knowledge`.

---

## ADR-061 — Integration registry in code, accounts in DB

**Контекст.** Можно хранить список провайдеров в таблице и динамически резолвить классы.

**Решение.** `IntegrationRegistry` в коде. `integration_accounts` хранит только connected state / credentials. Telegram status — virtual bridge без обязательной DB-строки.

**Следствие.** Новый provider = новый adapter + register(), не row «class name» в MySQL.

---

## ADR-062 — Credentials encrypted and never serialized

**Контекст.** Access/refresh tokens в plaintext JSON опасны в dump и Inertia.

**Решение.** Laravel `encrypted:array` на `credentials_encrypted`. Model hidden + `toArray` strip. Adapter getter only. Logs/UI never receive the blob or plaintext.

**Следствие.** DB dump не содержит usable tokens.

---

## ADR-063 — Telegram integration card reuses Channel source of truth

**Контекст.** Второй store bot token в `integration_accounts` разъедется с Settings → Telegram.

**Решение.** `TelegramIntegrationProvider` читает `telegram_bot_settings`. Не копирует token. Не создаёт account row ради UI.

**Следствие.** Один token store. Integrations card — overview.

---

## ADR-064 — ToolExecutionService centralizes logs and policy

**Контекст.** Логирование в каждом tool разъедется и начнёт писать секреты.

**Решение.** `ToolRegistry::execute` делегирует `ToolExecutionService`: capability, confirmation policy, `tool_execution_logs`, safe metadata, account last_used/error.

**Следствие.** Core и future integration tools проходят один pipeline. Multi-tool loop не схлопывается в one-call.

---

## ADR-065 — Model cannot self-authorize writes

**Контекст.** Модель может передать `authorized=true`.

**Решение.** Права только из `ToolExecutionContext.user` и server-side `explicitUserCommand`. Model arguments `authorized` / `confirmation` / `user_id` / `integration_account_id` игнорируются.

**Следствие.** Confirmation policy скелет готов для M18/M19. Precise NLP explicit-intent detection может эволюционировать.

---

## ADR-066 — OAuth state is server-side session controlled

**Контекст.** Callback без state — CSRF/account mix-up.

**Решение.** Cryptographic state + PKCE verifier in the owner session, TTL, one-time consume, bound to `user_id`. Return path is always Settings → Integrations.

**Следствие.** Invalid/expired/used state rejects without token exchange.

---

## ADR-067 — Google tokens only in encrypted IntegrationAccount credentials

**Контекст.** Client secret и user tokens в одном store или в UI.

**Решение.** Client ID/Secret = env. User access/refresh = `credentials_encrypted`. Never serialize to Inertia/JSON/logs.

**Следствие.** DB dump без APP_KEY не даёт usable Google tokens.

---

## ADR-068 — Refresh only through GoogleCredentialService

**Контекст.** M18/M19 adapters могут прочитать plaintext envelope.

**Решение.** Единственный путь к access token — `GoogleCredentialService::getValidAccessToken()`. lockForUpdate + refresh skew.

**Следствие.** Core не знает имена Google token fields.

---

## ADR-069 — Existing refresh_token is never overwritten by an absent response

**Контекст.** Google часто не возвращает refresh_token на повторный consent.

**Решение.** `mergeTokenResponse` сохраняет предыдущий refresh_token, если incoming пустой.

**Следствие.** Reconnect не ломает offline access.

---

## ADR-070 — One active Google account per owner (MVP)

**Контекст.** Schema допускает несколько Google accounts.

**Решение.** M17 UI — один active. Same `sub` updates. Different `sub` disconnects the previous connected account (revoke + wipe). Rows are not silently deleted.

**Следствие.** Нет duplicate connected Google accounts.

---

## ADR-071 — OAuth connection does not enable AI tools

**Контекст.** Connected Google может выглядеть как Calendar/Gmail ready.

**Решение.** M17 connection does not register Calendar/Gmail tools. M18 registers Calendar tools by capability; runtime still requires a connected account and Calendar scope.

**Следствие.** Identity connected ≠ Calendar ready ≠ Gmail ready.

---

## ADR-072 — Google Calendar is live external source, no local event mirror

**Контекст.** Локальный cache календарных событий расходится с Google.

**Решение.** M18 читает/пишет Google Calendar live через tools. Нет таблицы `calendar_events`, нет sync cron, нет webhook.

**Следствие.** Google остаётся source of truth. Jarvis не зеркалирует встречи.

---

## ADR-073 — Calendar access only through GoogleCredentialService

**Контекст.** Tokens лежат в `credentials_encrypted`.

**Решение.** `GoogleCalendarService` получает access token только через `GoogleCredentialService::getValidAccessToken()`. Tools не делают Google HTTP и не читают credentials.

**Следствие.** Refresh, revoke и lock остаются в одном месте.

---

## ADR-074 — Incremental Google scopes

**Контекст.** M17 identity scopes недостаточны для Calendar; будущий Gmail не должен попасть в M17/M18 connect.

**Решение.** Default connect остаётся identity-only. Enable Calendar запрашивает Calendar scope incrementally (`include_granted_scopes`). Stored scopes = union existing + newly granted. Refresh token preservation из M17 сохраняется.

**Следствие.** Gmail scopes запрашиваются только через incremental `?intent=gmail` (M19).

---

## ADR-075 — External writes use ToolConfirmationPolicy

**Контекст.** Model может предложить создать/изменить встречу без явной команды.

**Решение.** External write + `explicitUserCommand=true` → allowed. Model-proposed / unknown → `confirmation_required`. Сигнал команды задаёт application layer, не model args.

**Следствие.** `authorized=true` в arguments игнорируется.

---

## ADR-076 — Destructive Calendar delete requires persisted confirmation

**Контекст.** M16 skeleton возвращал `confirmation_required` без возможности подтвердить позже.

**Решение.** `tool_confirmations` в DB (encrypted arguments, user+conversation, TTL, one-time). Conservative yes/cancel parser + Web/Telegram buttons. Model cannot invent the token or self-confirm.

**Следствие.** Delete usable across Telegram/Web/restart. Expired/cancelled cannot execute.

---

## ADR-077 — Client-generated Google event id for create idempotency

**Контекст.** Calendar `events.insert` не имеет generic idempotency key.

**Решение.** Core генерирует Google-compatible event id из user/conversation/tool-call id (`[a-v0-9]`). Retry того же ToolCall повторяет id. Model не задаёт ключ.

**Следствие.** AI/tool retry не создаёт duplicate meeting.

---

## ADR-078 — Reminder remains a separate subsystem

**Контекст.** «Напомни» легко спутать с Calendar event.

**Решение.** `create_reminder` остаётся Core Reminder Engine. Calendar tools не создают reminders и наоборот.

**Следствие.** Delivery и Google Calendar не смешиваются.

---

## ADR-079 — Gmail is live source of truth

**Контекст.** Локальный mailbox mirror расходится с Gmail.

**Решение.** M19 читает/пишет Gmail live через tools. Нет таблиц `emails` / `gmail_messages` / `gmail_threads`.

**Следствие.** Google остаётся source of truth. Jarvis не зеркалирует inbox.

---

## ADR-080 — Gmail access only through GoogleCredentialService

**Контекст.** Tokens лежат в `credentials_encrypted`.

**Решение.** `GoogleGmailService` получает access token только через `GoogleCredentialService::getValidAccessToken()`. Tools не делают Gmail HTTP и не читают credentials.

**Следствие.** Refresh, revoke и lock остаются в одном месте для Calendar и Gmail.

---

## ADR-081 — Incremental Gmail scopes

**Контекст.** Identity/Calendar scopes недостаточны для Gmail; полный `mail.google.com` избыточен.

**Решение.** Enable Gmail запрашивает `gmail.readonly` + `gmail.compose` + `gmail.modify` incrementally (`include_granted_scopes`). Stored scopes = union existing identity + Calendar + Gmail. Refresh token preservation из M17 сохраняется.

**Следствие.** Connected Google ≠ Gmail-enabled. Card показывает Identity / Calendar / Gmail отдельно.

---

## ADR-082 — Email send always requires persisted confirmation

**Контекст.** Send имеет внешний side effect и Gmail `messages.send` не даёт generic idempotency.

**Решение.** `send_gmail_message` всегда `confirmation_required` (`ToolMeta.alwaysConfirm`), даже при явной команде «отправь». One-time `tool_confirmations` execute блокирует повторную отправку. Preview: recipients + subject + bounded body.

**Следствие.** Duplicate confirm/cancel/expire не шлёт письмо. Cross-user confirm denied.

---

## ADR-083 — Gmail write HTTP calls are not auto-retried

**Контекст.** Retry `drafts.create` / `messages.send` / modify может дублировать действие.

**Решение.** GET/search/read могут ограниченно retry. Write HTTP retry = 0. Отдельная idempotency-таблица не вводится: send закрыт confirmation one-time; draft retry risk документирован.

**Следствие.** Нет слепого duplicate send из HTTP layer.

---

## ADR-084 — No Gmail mailbox mirror or polling

**Контекст.** Proactive inbox monitoring потребует watch/historyId и локальный store.

**Решение.** M19 = on-demand tools only. Нет cron, push, `users.watch`, History API.

**Следствие.** «Есть новые письма?» идёт через `list_gmail_messages` / `search_gmail` в conversation turn.

---

## ADR-085 — Draft ≠ send

**Контекст.** Черновик и отправка легко смешать в одном tool.

**Решение.** `create_gmail_draft` только создаёт draft. `send_gmail_message` только шлёт (после confirm). Reply = те же tools с `reply_to_message_id` / `thread_id` и корректными MIME headers. Отдельный reply tool не нужен.

**Следствие.** «Сделай черновик» не отправляет письмо. Attachments outbound и trash/delete отложены.

---

## ADR-086 — Admin Panel ≠ Personal Workspace

**Контекст.** Owner легко начинает «болтать» в админке.

**Решение.** Admin = техническое управление (users, AI, integrations, groups, diagnostics, logs, settings). Personal Workspace = общение с Jarvis. Owner не использует Admin как основной chat UI.

**Следствие.** Route is `/jarvis`. `/cabinet` remains User Space. Admin stays at `/dashboard`.

---

## ADR-087 — Conversation is the central UX entity

**Контекст.** Projects, mail, calendar, memory конкурируют за экран.

**Решение.** В Workspace центр — conversation (text + voice). Остальные панели вторичны.

**Следствие.** Нет inbox-first или calendar-first owner home.

---

## ADR-088 — Web / Desktop / Mobile share one Jarvis Core

**Status.** Partially SUPERSEDED: Desktop cancelled (ADR-235). Core-ownership of memory/tools/credentials remains. Mobile deferred (ADR-237). Client API deferred (ADR-238).

**Контекст.** Нативные клиенты соблазняют локальным AI.

**Решение.** Все клиенты — adapters. Memory, tools, credentials, provider selection — только Core.

**Следствие.** Web uses Laravel/Inertia. Versioned Client API is not a current milestone.

---

## ADR-089 — Voice is a mode, not a separate assistant

**Контекст.** Voice легко оформить как второй продукт.

**Решение.** Voice Mode на выбранном `conversation_id`. Нет отдельных voice memories и нет auto-created voice chat.

**Следствие.** Runtime ≠ Orb UI. Provider можно сменить без нового ассистента.

---

## ADR-090 — Same conversation continues across clients

**Status.** Core idea remains (one `conversation_id` per space). Desktop cancelled.

**Контекст.** Telegram / Web / Mobile иначе плодят треды.

**Решение.** Один `conversation_id` на все каналы одного space. New Chat — только явный выбор.

**Следствие.** History смешивается хронологически в одном catalog.

---

## ADR-091 — Desktop = Tauri 2 + React / TypeScript

**Status.** CANCELLED / SUPERSEDED by ADR-235. File `Docs/CLIENTS/DESKTOP_APP.md` removed.

**Контекст.** Нужен native desktop без второго Core.

**Решение (historical).** Tauri 2, React, TS, Vite, Three.js/WebGL для Orb. Thin client.

**Следствие.** Do not plan Tauri, JARVIS-Desktop, tray, or global hotkey.

---

## ADR-092 — Mobile = Flutter

**Status.** DEFERRED as product work (ADR-237). Flutter remains the sketched stack if Mobile is ever built; not a current milestone.

**Контекст.** iOS и Android должны делить один client codebase.

**Решение.** Flutter latest stable. Нет прямых Google API с устройства.

**Следствие.** [CLIENTS/MOBILE_APP.md](CLIENTS/MOBILE_APP.md).

---

## ADR-093 — Desktop and Mobile are separate repositories

**Status.** SUPERSEDED for Desktop (ADR-235). Mobile repo remains a possible future if Mobile is built; not current work.

**Контекст.** Rust/Tauri и Flutter внутри Laravel repo засоряют CI, deploy и Cursor context.

**Решение (historical).** `Owiiiii1/JARVIS` (Core + Admin + Workspace). `Owiiiii1/JARVIS-Desktop`. `Owiiiii1/JARVIS-Mobile`.

**Следствие.** Production backend has no Tauri/Flutter tree. Desktop repo will not be created.

---

## ADR-094 — Orb visualization is provider-neutral

**Контекст.** Смена ElevenLabs/OpenAI/Gemini не должна переписывать UI.

**Решение.** Orb читает `VoiceVisualizationState` (state, amplitudes, bands, connection). Runtime мапит vendor audio в этот контракт.

**Следствие.** [CLIENTS/VOICE_UI.md](CLIENTS/VOICE_UI.md).

---

## ADR-095 — GitHub goes through the Integration Framework

**Контекст.** «Посмотри commit» легко сделать в Telegram adapter или локальном git.

**Решение.** GitHub — owner-only provider + tools. Credentials в `integration_accounts`. Implemented in M21 (OAuth App MVP). Live validation deferred by Owner.

**Следствие.** Read + controlled write (issue/comment/branch/PR create). Не в Channel Layer. GitHub App installations may follow.

---

## ADR-096 — GitHub OAuth App MVP; GitHub App later

**Контекст.** Granular per-repo installation is better long-term but heavier.

**Решение.** M21 uses a GitHub OAuth App (`repo` + `read:org`). A GitHub App installation model may replace or extend this later.

**Следствие.** `repo` is broad; document why. No PAT in Admin.

---

## ADR-097 — GitHub is a live external source of truth

**Контекст.** Local commit mirrors go stale.

**Решение.** No `github_*` content tables. On-demand REST tools only.

**Следствие.** No webhook/polling in M21.

---

## ADR-098 — GitHub credentials only through encrypted IntegrationAccount

**Контекст.** PAT fields leak through UI and logs.

**Решение.** OAuth token envelope in `credentials_encrypted`. Tools use `GitHubCredentialService`. Envelope never serializes.

**Следствие.** Disconnect wipes local credentials even if remote revoke fails.

---

## ADR-099 — GitHub HTTP only through GitHubApiService

**Контекст.** Tool classes otherwise copy headers and leak tokens.

**Решение.** All REST calls live in `GitHubApiService`. Central Accept / API version / User-Agent / Authorization.

**Следствие.** Provider can change without rewriting each tool.

---

## ADR-100 — GitHub retrieval is explicit and tool-driven

**Контекст.** Private repos must not appear in every prompt.

**Решение.** No automatic GitHub context injection. No automatic memory ingestion.

**Следствие.** Owner must ask; Conversation AI calls tools.

---

## ADR-101 — No repository mirror and no shell git for integration

**Контекст.** `git clone` on the server is a different product.

**Решение.** GitHub API only. No clone/pull/log for conversational GitHub.

**Следствие.** Local repo operations remain out of scope.

---

## ADR-102 — M21 write surface is issue / comment / branch / PR create

**Контекст.** Full write access via `repo` is dangerous.

**Решение.** Implement only create issue, comment, create branch, create PR. Config `allowed_write_operations`.

**Следствие.** No merge, delete, force, file write, workflow edit, secrets.

---

## ADR-103 — No merge / delete / force operations in M21

**Контекст.** Merge and delete are hard to undo.

**Решение.** Do not register those tools. Branch create refuses overwrite.

**Следствие.** Risk stays limited even with `repo` scope.

---

## ADR-104 — GitHub writes use standard confirmation; not alwaysConfirm

**Контекст.** Send-mail is alwaysConfirm because it is externally visible and easy to spam.

**Решение.** GitHub issue/comment/branch/PR follow M16 external-write policy (explicit allowed, model-proposed confirmation_required). Not alwaysConfirm.

**Следствие.** No merge means residual risk is manageable.

---

## ADR-105 — M21 tests and live GitHub validation are deferred by Owner

**Контекст.** Production DB tests and live OAuth are high-risk without Owner smoke windows.

**Решение.** Implement M21 without `php artisan test` and without connecting a real GitHub account.

**Следствие.** Status: implemented, not live-validated. Combined Google smoke remains deferred.

---

## ADR-110 — `/jarvis` is Owner Personal Workspace

**Контекст.** Docs allowed `/workspace` or `/jarvis`.

**Решение.** Owner Personal Workspace route is `/jarvis`. Not `/workspace`. Not `/dashboard`. Not `/cabinet`.

**Следствие.** Route names live under the `jarvis.*` namespace.

---

## ADR-111 — Owner default landing is Workspace

**Контекст.** Owner login previously opened Admin.

**Решение.** Ordinary owner login redirects to `/jarvis`. `intended()` still honours an explicit Admin URL. Admin stays reachable from Workspace and via **Open Jarvis** from Admin.

**Следствие.** Admin is technical, not the default messenger.

---

## ADR-112 — Owner `/cabinet` redirects to `/jarvis`

**Контекст.** Owner could open the User Cabinet messenger.

**Решение.** Owner hitting `/cabinet` (and cabinet chat URLs) redirects to Workspace. `role=user` Cabinet is unchanged.

**Следствие.** One owner web messenger. No dual owner UIs.

---

## ADR-113 — Workspace uses the same `conversations` / `messages`

**Контекст.** Temptation to add `workspace_conversations`.

**Решение.** Workspace reads and writes the existing personal catalog. Telegram chats of the owner appear in `/jarvis`. New Chat is a normal personal conversation.

**Следствие.** No workspace-specific storage schema.

---

## ADR-114 — Workspace inbound channel remains `web`

**Контекст.** Branding could invent `channel=workspace`.

**Решение.** Channel names the transport. Workspace messages use `web` + `client_message_id` UUID, same as Cabinet.

**Следствие.** No new channel enum for M22.

---

## ADR-115 — Personal settings vs technical admin settings

**Контекст.** Owner needs General Prompt in the messenger without seeing API keys.

**Решение.** Workspace: General Prompt, timezone display, voice prefs later, integrations status + Admin deep link. Admin: providers, models, OAuth, workers, webhooks.

**Следствие.** Workspace must not reproduce OAuth forms or AI vendor config.

---

## ADR-116 — Voice Mode is a placeholder in M22

**Контекст.** Voice UI docs describe Orb + runtime.

**Решение.** M22 ships Text/Voice toggle, CSS Orb placeholder, and `VoiceModePlaceholder` / future `<VoiceSession conversationId>` boundary. No microphone, STT, TTS, WebRTC, ElevenLabs, or Three.js.

**Следствие.** M23 replaced the placeholder with `VoiceSession` without changing conversation identity. Historical for M22.

---

## ADR-118 — Vision images are current-turn payload, not replayed context blobs

**Контекст.** Multimodal turns must not resend every historical screenshot on every later message.

**Решение.** Only the current inbound message’s image bytes are converted to `AiContentPart` image parts. Previous attachments stay as text placeholders (`[N images attached]`) plus whatever the assistant already said. No attachment-specific memory ingestion.

**Следствие.** Explicit historical-image retrieval can be added later. Telegram/Web/Mobile can reuse `message_attachments` without changing this policy. Desktop cancelled.

---

## ADR-119 — Copyable artifacts are distinct from fenced code

**Контекст.** Jarvis often returns prompts, configs, and drafts that should be copied in one click. Ordinary code fences are already used for illustrative snippets.

**Решение.** Provider-neutral markdown: ` ```artifact Title ` renders as an Artifact block with Copy of raw text. Language-tagged fences remain Code blocks with Copy. SafeMarkdown parses this in Core UI; adapters do not emit vendor-specific widgets.

**Следствие.** Models are instructed to use artifact fences only for copy-paste payloads.

---

## ADR-120 — Chat images are private and ownership-gated

**Контекст.** Screenshots must not get permanent public URLs.

**Решение.** Store on the private `local` disk under `chat-attachments/`. Preview/full routes require auth + conversation/message/attachment ownership. Clients receive only route URLs, never filesystem paths. Limits live in `config/chat_attachments.php`.

**Следствие.** Web Workspace and Telegram reuse the same access service. Future Mobile could mount it. Desktop cancelled.

---

## ADR-121 — OpenAI/Anthropic vision is not faked in M22.1

**Контекст.** Production conversation path is Gemini. OpenAI and Anthropic adapters are text-only.

**Решение.** `supportsVision()` is true only for Gemini. Other providers return `vision_not_supported` instead of dropping images.

**Следствие.** Adding vision to another adapter is an adapter change, not a Conversation Engine rewrite.

---

## ADR-122 — Telegram photo ingestion is later, same entity

**Контекст.** M22.1 is Web-first.

**Решение.** Do not ingest Telegram photos now. The `message_attachments` schema is not image-only and is not Web-only.

**Следствие.** A later Telegram adapter can persist the same rows and call the same Conversation Engine.

---

## ADR-123 — M22.1 tests and live vision are deferred by Owner

**Контекст.** Production DB tests and live Gemini vision are high-risk.

**Решение.** Implement M22.1 without `php artisan test` and without live AI vision / Google / GitHub.

**Следствие.** Status: implemented, not validated.

---

## ADR-124 — Screenshots are ephemeral media (default 24h)

**Контекст.** Chat images are for the current multimodal turn and a short follow-up window, not a photo library.

**Решение.** Default `retention_class=ephemeral`, `expires_at = created_at + config retention` (24 hours). Configurable. No “save screenshot forever” in M22.2.

**Следствие.** After expiry only a textual visual summary remains. Persistent images would be a future explicit action.

---

## ADR-125 — Image originals purge only after summary readiness, with hard fallback

**Контекст.** Purging before a summary would lose all visual memory of the screenshot.

**Решение.** Soft purge: `expires_at <= now` AND `summary_status=ready`. Failed summaries keep the original until `hard_retention_days` (7). After hard retention, purge bytes and leave metadata that the summary is unavailable. Bounded hourly command. No mass delete in migration.

**Следствие.** Historical M22.1 rows stay valid; scheduler performs lifecycle.

---

## ADR-126 — Screenshot summaries are derived attachment metadata, not personal memory

**Контекст.** The assistant reply (“yes, file permissions”) is not enough months later to know what was on the screenshot.

**Решение.** Dedicated `summary_text` / `summary_status` via `AttachmentVisionSummaryService`. Memory Engine may still extract durable user facts from normal conversation text. It must not bulk-ingest screenshot summaries or Storage contents.

**Следствие.** Historical context uses `[Previous screenshot summary: …]`.

---

## ADR-127 — Persistent user files live in Jarvis Storage

**Контекст.** Owner needs a personal file library for logs and source, separate from chat screenshots.

**Решение.** `stored_files` + `stored_file_chunks` + `/jarvis/storage`. Owner-only. Permanent until delete. Private disk. Text/source formats only in M22.2.

**Следствие.** PDF/Office/images-as-Storage are later extractors.

---

## ADR-128 — `message_attachments` and `stored_files` have separate lifecycle semantics

**Контекст.** Mixing chat media and the document library would break retention, retrieval, and security.

**Решение.** Screenshots stay on `message_attachments`. Documents stay on `stored_files`. Optional `message_stored_files` pivot when a StoredFile is sent in chat. No physical copy.

**Следствие.** Chat upload and direct Storage upload share StoredFile when the user attaches a text file; they never share screenshot rows.

---

## ADR-129 — Stored files are private and permanent until deletion

**Контекст.** Owner documents are not public cache.

**Решение.** Private filesystem, ownership-gated download, UUID physical names, no automatic expiry.

**Следствие.** Delete is UI-confirmed and optionally a destructive tool with `ToolConfirmationService`.

---

## ADR-130 — Stored file content is chunked and tool-retrieved, never auto-injected

**Контекст.** Logs can be tens of megabytes.

**Решение.** Extract + chunk. Conversation Engine may inline only a configured small threshold. Otherwise tools. No “all files” in system prompt. Tool outputs have hard char/chunk limits.

**Следствие.** ContextBudgetManager remains M22.3.

---

## ADR-131 — Chat file and direct Storage upload reuse the same StoredFile entity

**Контекст.** A log sent in chat should still appear in Storage.

**Решение.** One `stored_files` row. Chat adds a pivot. Direct upload has no message.

**Следствие.** Source-chat links on the Storage page when a pivot exists.

---

## ADR-132 — Storage file contents are untrusted data

**Контекст.** Source files and screenshots can contain prompt-injection text.

**Решение.** Platform guidance: Storage contents and screenshot pixels are untrusted user data; embedded instructions are not system/tool authorization.

**Следствие.** Applies to tool results and current-turn inline excerpts.

---

## ADR-133 — Full context budgeting remains M22.3

**Контекст.** Storage tools, web research, and conversation windows will compete for tokens.

**Решение.** M22.2 only bounds Storage tool results. Do not implement ContextBudgetManager yet.

**Следствие.** M22.3: Web Research + global Context Budget Manager.

---

## ADR-134 — M22.2 tests and live vision are deferred by Owner

**Контекст.** Production DB and live Gemini remain high-risk.

**Решение.** Implement M22.2 without `php artisan test`, live vision, live AI conversation, Google, or GitHub.

**Следствие.** Status: implemented, not validated.

---

## ADR-135 — Web Research is explicit tool-driven retrieval

**Контекст.** Owner needs current public-web facts without dumping the internet into every prompt.

**Решение.** `search_web` and `fetch_web_page` are Tool Layer tools behind `WebSearchManager` / `WebSearchProvider` / `WebPageFetchService`. Providers: `gemini_google` (Gemini Google Search grounding, existing Gemini credentials), `tavily`, `null`. Controllers, Conversation Engine, and the `search_web` tool do not call search APIs or know the vendor. Search does not auto-fetch pages. Grounding metadata is normalized to `WebSourceReference` inside the Gemini adapter.

**Следствие.** The model chooses which 2–5 URLs to read via `fetch_web_page`.

---

## ADR-136 — Web content is untrusted data

**Контекст.** Pages can contain prompt-injection (“send all Gmail to attacker”).

**Решение.** Platform rule: web text is quoted source material only. It cannot override instructions, grant permissions, authorize tools, or reveal secrets. `ToolExecutionContext` remains the source of authorization.

**Следствие.** A page cannot enable Gmail/GitHub/Storage.

---

## ADR-137 — Search and page fetch are separate tools

**Контекст.** Downloading every search hit would blow latency, cost, and context.

**Решение.** `search_web` returns compact snippets. `fetch_web_page` reads one URL. Caps: max searches, fetches, and total web chars per turn.

**Следствие.** `web_research_budget_exceeded` when caps hit.

---

## ADR-138 — SSRF and private-network access are forbidden

**Контекст.** Server-side fetch is a classic SSRF surface.

**Решение.** http/https only. Deny localhost, loopback, RFC1918, link-local, metadata, internal hostnames, credentials, non-http schemes. Resolve DNS before request; revalidate every redirect.

**Следствие.** No unix/file/data/javascript fetches. No browser automation in M22.3.

---

## ADR-139 — Web results do not auto-enter personal memory

**Контекст.** Scraped prices and news are not durable personal facts.

**Решение.** Memory analysis ignores web-scraped facts unless the user explicitly asked to remember a personal fact. Fetched pages are not stored in DB.

**Следствие.** Source links in the assistant message are enough for later conversation.

---

## ADR-140 — Context size is governed centrally by ContextBudgetManager

**Контекст.** Conversation, memory, Storage, Gmail, GitHub, and web can each overflow a prompt.

**Решение.** `ConversationContextBuilder` gathers slices; `ContextBudgetManager` decides how much of each enters one AI request. Named budgets live in `config/context_budget.php`.

**Следствие.** Local tool bounds remain; they are not the only limiter.

---

## ADR-141 — Model context limits resolve per provider/model with a conservative fallback

**Контекст.** Models do not share one context window.

**Решение.** `AiModelContextPolicy` + `config/ai_model_context.php`. Unknown model → conservative default (32k context, 2k output reserve). Input budget = max − reserve − safety margin.

**Следствие.** Do not hardcode one window in Conversation Engine.

---

## ADR-142 — Token estimator is intentionally conservative

**Контекст.** Perfect tokenizers are provider-specific.

**Решение.** `TokenEstimator` overestimates via Unicode-aware chars/words + overhead. Prefer overestimating. Provider usage can later show drift in logs.

**Следствие.** Before each provider call, estimated input must be ≤ input budget.

---

## ADR-143 — Recent history raw window is token-bounded

**Контекст.** A message-count window still explodes if messages are huge.

**Решение.** Take newest messages backwards until the recent-token budget is exhausted. Preserve complete message boundaries. Count limits remain query bounds only.

**Следствие.** Emergency minimum recent context is kept; old memories are dropped first.

---

## ADR-144 — Old history is summary-first / raw-on-demand

**Контекст.** Lifetime chats cannot be stuffed into the prompt.

**Решение.** Keep current architecture: current `conversation_summary` for older same-chat; cross-chat summaries; raw other chats only via `search_conversation_history`.

**Следствие.** DB size does not imply prompt size.

---

## ADR-145 — Global ToolResult budget is a second safety layer

**Контекст.** A GitHub diff, Gmail thread, web page, or Storage log can overflow even when base context is small.

**Решение.** `ToolResultBudgetManager` trims every ToolResult. Preserve structure; trim content first. Shared per-turn tool budget. Exhausted → `tool_context_budget_exceeded`.

**Следствие.** Gmail/GitHub/Web/Storage cannot independently overflow context.

---

## ADR-146 — Compaction never deletes raw conversation

**Контекст.** Summaries are derived.

**Решение.** `UpdateConversationSummaryJob` still writes versions. Raw `messages` stay. Coverage uses existing `from_message_id` / `to_message_id`.

**Следствие.** No M22.3 destructive data migration.

---

## ADR-147 — Summaries update incrementally and stay bounded

**Контекст.** Re-summarizing a lifetime chat every turn would be unbounded.

**Решение.** Previous summary + unsummarized range (capped). Refresh on message **or** token threshold. Cap summary chars and recompress if needed.

**Следствие.** Unsummarized queries use LIMIT. No all-history summarizer prompt.

---

## ADR-148 — Database size must not imply prompt size

**Контекст.** Owner scale goal: 1M messages still yields a bounded one-turn request.

**Решение.** After summary threshold, extra raw rows do not materially grow the normal prompt. Only retrieval/index cost may grow. Persistent files stay on disk; object storage is a future threshold, not this milestone.

**Следствие.** Context diagnostics log metrics only, never private texts.

---

## ADR-149 — M22.3 tests and live web/AI are deferred by Owner

**Контекст.** Production DB and live Tavily/AI remain high-risk.

**Решение.** Implement M22.3 without `php artisan test`, live search, live fetch, live AI conversation, Google, or GitHub.

**Следствие.** Status: implemented, not validated.

---

## ADR-150 — Web Research provider is Admin-configurable infrastructure

**Контекст.** `.env` alone is not operable for Owner. Workspace is a chat surface, not a technical console.

**Решение.** Provider, enablement, fetch toggle, and bounded limits live in Admin → Settings → Integrations → Web Research (`web_research_settings`). Runtime reads `WebResearchSettingsService` only.

**Следствие.** `/jarvis` is not the editor. Workspace may show read-only `Web Search · Google|Tavily|Disabled`.

---

## ADR-151 — Provider selection is not a per-conversation preference

**Контекст.** Mixing Gemini grounding and Tavily per chat would fork tool semantics and credentials.

**Решение.** One instance-level provider: `gemini_google` | `tavily` | `disabled`. No conversation-level override.

**Следствие.** Changing provider in Admin applies to the next Owner Conversation turn.

---

## ADR-152 — Gemini Google Search is a WebSearchProvider, not Conversation Engine logic

**Контекст.** Gemini Google Search grounding is vendor-specific (request shape, grounding metadata).

**Решение.** `GeminiGoogleSearchProvider` implements `WebSearchProvider`. Conversation Engine, `SearchWebTool`, and Gemini chat `chat()` stay vendor-neutral. No Google payload in the conversation client.

**Следствие.** Search discovery can switch to Tavily/disabled without rewriting the engine.

---

## ADR-153 — Gemini Search reuses the existing Gemini credential

**Контекст.** Duplicating the Gemini API key into web research settings would split secret lifecycle.

**Решение.** `GeminiGoogleSearchProvider` reads `ai_provider_settings` where `provider=gemini` (`is_connected` + encrypted `api_key`). Admin shows configured yes/no only. No Gemini key field on the Web Research card.

**Следствие.** Disconnecting Gemini in AI settings makes Google Search “not configured”.

---

## ADR-154 — Tavily remains an independent fallback provider

**Контекст.** Tavily is not Google Search and must remain selectable.

**Решение.** Keep `TavilyWebSearchProvider`. Tavily key is a separate encrypted credential (`web_research_settings.tavily_api_key`) with env `WEB_SEARCH_API_KEY` fallback. Do not delete Tavily.

**Следствие.** Admin can choose Tavily without using Gemini grounding.

---

## ADR-155 — fetch_web_page remains vendor-neutral server fetch

**Контекст.** Gemini grounding is discovery, not a safe page reader. SSRF policy is ours.

**Решение.** `fetch_web_page` always uses `WebPageFetchService` + `WebUrlGuard`, never Gemini grounding or Tavily extract.

**Следствие.** Fetch limits/timeout come from effective Admin settings; SSRF rules stay immutable.

---

## ADR-156 — Admin-configurable limits are bounded by immutable safety ceilings

**Контекст.** An Admin typo must not disable context or SSRF safety.

**Решение.** `effective = min(admin_setting, hard_safety_ceiling)` with floors. Ceilings live in `config/web_research.php`. `TurnBudgetTracker` and fetch/search use effective values. `ContextBudgetManager` remains the final prompt safety layer.

**Следствие.** Admin cannot raise searches/fetches/chars/timeout above code ceilings.

---

## ADR-157 — SSRF and security policy cannot be disabled in Admin

**Контекст.** Server-side fetch is an SSRF surface. Prompt injection on pages is mandatory to treat as untrusted.

**Решение.** No Admin switches for private IP, localhost, schemes, redirect validation, or prompt-injection protection.

**Следствие.** Those controls stay code/config only.

---

## ADR-158 — Secrets never round-trip to the frontend

**Контекст.** Inertia payloads are visible in the browser.

**Решение.** Never return Gemini or Tavily plaintext keys. Tavily UI is set/replace + configured/not configured (+ optional clear). Gemini has no key input on this card.

**Следствие.** Masked/configured status only.

---

## ADR-159 — Tests and live search remain deferred

**Контекст.** M22.3.1 adds Admin settings and Gemini provider wiring on production.

**Решение.** No `php artisan test`, no PHPUnit, no live Google Search, no live Tavily, no live fetch, no live AI. Status from configuration presence only. No Test Connection button.

**Следствие.** Implemented / not validated. Later milestone may add live smoke.

---

## ADR-160 — Owner manual production validation (vision, Storage, Gemini web search)

**Контекст.** M22.1–M22.3.1 shipped as implemented / not validated. Automated tests were not run. Owner later used production Workspace and confirmed specific functions.

**Решение.** Record **MANUAL PASS** only for: Workspace image upload; Gemini vision recognition; persistent text-file upload; persistent Storage retrieval/read; Gemini Google Search web research (current information). Admin Gemini Google Search configuration is PASS only insofar as that working search path required it. Do not mark whole milestones validated. Do not mark `fetch_web_page`, Tavily, SSRF, ContextBudgetManager, screenshot purge/summarization, Storage library UI, destructive delete, artifact copy, Google Calendar/Gmail combined smoke, or GitHub runtime as PASS.

**Следствие.** Automated tests remain not run. Status vocabulary includes MANUAL PASS vs IMPLEMENTED / NOT VALIDATED.

---

## ADR-161 — Voice is a modality over an existing conversation

**Контекст.** Voice легко оформить как второго ассистента.

**Решение.** Audio → STT → ordinary user turn → `ConversationTurnService` → TTS. Session always has `user_id` + existing `conversation_id`. Text ↔ Voice must not create a new conversation.

**Следствие.** No second Jarvis / User Space / voice chat.

---

## ADR-162 — Voice transcripts are ordinary messages

**Контекст.** Temptation to store `voice_messages`.

**Решение.** Final STT text and assistant text are normal `messages` rows. Modality lives in metadata (`modality=voice`, `voice_session_public_id`). Channel `web` vs modality `voice` stay distinct. `MessageType::Voice` remains Telegram inbound voice notes.

**Следствие.** No `voice_messages` / `voice_history` tables.

---

## ADR-163 — No voice-specific memory

**Контекст.** Long spoken sessions look like a separate memory problem.

**Решение.** One Memory Engine. No `voice_memory`.

**Следствие.** Voice cannot fork Owner/User memory.

---

## ADR-164 — STT/TTS are provider-neutral ports

**Контекст.** Vendor SDKs leak into Core.

**Решение.** `SpeechToTextProvider` / `TextToSpeechProvider` + managers. Runtime does not instantiate vendor clients. Null providers return `voice_stt_not_configured` / `voice_tts_not_configured`. Optional `RealtimeDuplexSpeechProvider` is future-only; STT and TTS remain canonical.

**Следствие.** ElevenLabs/Whisper can change without rewriting Conversation Engine.

---

## ADR-165 — Voice Runtime and Voice UI are separate

**Контекст.** Orb work can swallow the runtime.

**Решение.** M23 = runtime + CSS client boundary. M24 = final Orb. UI consumes session state/events; it does not own STT/TTS.

**Следствие.** Three.js is not required to ship Voice Runtime.

---

## ADR-166 — Conversation AI config is unchanged by Voice providers

**Контекст.** Selecting ElevenLabs or Whisper could silently retarget Owner Conversation AI.

**Решение.** Voice STT/TTS settings are Integrations technical settings. Owner Conversation AI stays `ai_role_settings` / `ai_provider_settings` for chat.

**Следствие.** Whisper may reuse an OpenAI key only for `/audio/transcriptions`, never `chat()`.

---

## ADR-167 — Interrupting playback does not delete the persisted assistant message

**Контекст.** Barge-in might tempt a delete of “unspoken” text.

**Решение.** If assistant text is already persisted, keep it. Optional `voice_playback_interrupted=true`.

**Следствие.** History is what Jarvis intended to say, not only what was heard.

---

## ADR-168 — Raw audio is ephemeral by default

**Контекст.** Storing recordings forever is a privacy/storage trap.

**Решение.** Temp private files → STT → delete on success; short retry window on failure; `jarvis:voice:cleanup-temp`. Source of truth is the transcript. Optional archive is a later product decision. No `raw_audio_archive` table.

**Следствие.** Cleanup never deletes messages.

---

## ADR-169 — Long voice sessions use the same ContextBudgetManager

**Контекст.** Hours of transcripts could dump into one prompt.

**Решение.** Voice messages are ordinary messages, so summary-first budget applies. No separate voice context window.

**Следствие.** A 5-hour session must not create a 5-hour prompt.

---

## ADR-170 — Web / desktop / mobile share VoiceRuntimeService

**Status.** SUPERSEDED in part by ADR-235/238. Shared `VoiceRuntimeService` remains. Desktop will not call it. Mobile may later; Client API deferred.

**Контекст.** Web controller could become the Core.

**Решение.** HTTP Workspace controller is an adapter. Future non-Web clients would call the same runtime if/when they exist.

**Следствие.** Do not duplicate STT/turn/TTS in clients. Do not build Client API solely for cancelled Desktop.

---

## ADR-171 — Telephony is a future adapter, not M23

**Контекст.** Twilio/SIP looks like “voice”.

**Решение.** No Twilio Voice, SIP, PSTN, phone routing, or call recording in M23. Phone is a later adapter over Voice Runtime.

**Следствие.** M23 is Workspace/session voice, not a call center.

---

## ADR-172 — Final Orb is M24

**Контекст.** Visual Orb can consume the milestone.

**Решение.** M23: state/event contract + CSS orb. M24: Three.js/WebGL Orb.

**Следствие.** Do not ship shaders to claim Voice Runtime.

---

## ADR-173 — Tests and live voice validation remain deferred

**Status.** SUPERSEDED as current Voice status by ADR-239. Automated tests still not claimed. Owner later confirmed Voice MANUAL PASS.

**Контекст.** Production Owner policy: no `php artisan test`, no live STT/TTS/AI during M23/M24 implementation work.

**Решение (historical for those milestones).** Static/build/migrate/route/schedule verification only during implementation.

**Следствие.** Do not treat this ADR as current Voice validation status.

---

---

## ADR-174 — Initial STT is Whisper-or-Null; Gemini STT is M23.1

**Контекст.** Gemini `generateContent` with audio would contaminate Conversation AI.

**Решение.** M23 ships the STT port, Null, and an OpenAI Whisper adapter on the dedicated transcriptions API. Default STT is `none`. Do not hack Gemini chat for transcription. A dedicated Gemini STT adapter, if needed, is M23.1.

**Следствие.** Unconfigured STT is a safe `voice_stt_not_configured`, not a fatal crash.

---

## ADR-175 — Orb is provider-neutral

**Контекст.** A vendor SDK in the renderer would lock Voice UI.

**Решение.** `JarvisVoiceOrb` consumes only `VoiceVisualizationState`. No ElevenLabs/STT/Conversation AI fields.

**Следствие.** Speech providers can change without rewriting shaders.

---

## ADR-176 — Runtime state and audio analysis are separate

**Контекст.** Backend session status is not microphone energy.

**Решение.** `voice_sessions.status` maps to visualization `state`. Amplitudes and bands come from local `VoiceAudioAnalyzer` (or demo synthetic output energy).

**Следствие.** Listening can look alive without STT. Thinking does not fake a waveform.

---

## ADR-177 — Input analyser is local browser-only

**Контекст.** Visualization needs mic energy before providers exist.

**Решение.** Web Audio `AnalyserNode` after Start Voice. No upload, no archive, no backend requirement for demo visualization.

**Следствие.** M23 temp-audio policy is unchanged.

---

## ADR-178 — Orb does not own microphone or session lifecycle

**Контекст.** Putting getUserMedia inside the renderer couples UI to auth/HTTP.

**Решение.** `VoiceSession` owns permission, tracks, session HTTP. The Orb only renders.

**Следствие.** A future Mobile companion could reuse the engine with a different session client. Desktop cancelled.

---

## ADR-179 — Three.js visualization engine has no Laravel dependency

**Контекст.** Visualization must not depend on Inertia. (Historical: Desktop was Tauri + React.)

**Решение.** Engine lives in `resources/js/voice/visualization` without Inertia, Ziggy, or Blade.

**Следствие.** Flutter does not reuse Three.js; it keeps the same state contract.

---

## ADR-180 — Mock/demo mode exists until providers are connected

**Контекст.** Owner needs to judge the Orb without live STT/TTS.

**Решение.** `?voice_demo=1` or `VITE_VOICE_DEMO_MODE`. Hidden drawer cycles states. Synthetic speaking energy is labeled demo, not fake TTS.

**Следствие.** Do not show the drawer in normal Voice chrome.

---

## ADR-181 — No OrbitControls in product Voice UI

**Контекст.** Dragging the sphere turns it into a toy.

**Решение.** Fixed cinematic framing. Subtle procedural camera drift only.

**Следствие.** No user orbit/pan/zoom of the Orb.

---

## ADR-182 — WebGL fallback is required

**Контекст.** Some browsers/devices have no WebGL.

**Решение.** Polished CSS orb + readable state text. Controls still work.

**Следствие.** Missing WebGL is not a Voice Mode crash.

---

## ADR-183 — M24 does not change voice memory or runtime semantics

**Контекст.** A visual milestone could tempt a second voice history.

**Решение.** No new tables. Same conversation. Same `VoiceRuntimeService`. Transcripts remain ordinary messages.

**Следствие.** M24 is frontend visual/product layer only.

---

## ADR-184 — Owner and User share one Personal Workspace product

**Контекст.** Cabinet was a simpler chat. Product decision: ordinary users get the same chat product.

**Решение.** One Shared Personal Workspace frontend and `PersonalChatSurfaceService`. Role/capabilities gate features. No second Conversation Engine.

**Следствие.** Do not fork composer, message renderer, or Voice UI.

---

## ADR-185 — Role and capabilities change features, not chat implementation

**Решение.** Owner chrome (projects, integrations, Admin, Storage page) is additive. User sees the same thread/composer/Orb without those panels.

---

## ADR-186 — One login form for Owner and User

**Решение.** Email + password. No self-registration. Accounts created only by Owner via Admin → Users.

---

## ADR-187 — Login landing `/jarvis` vs `/chat`

**Решение.** Owner → `/jarvis`. User → `/chat`. Owner `/chat` → `/jarvis`. User `/jarvis` → `/chat`.

---

## ADR-188 — User self-registration forbidden

**Решение.** No register route/page. Access code remains Telegram pairing only, not a web password.

---

## ADR-189 — User accounts only Owner-created

**Решение.** Admin Users catalog is the only account factory.

---

## ADR-190 — Users get instance Web Research by default

**Решение.** Capability `web_research` is in the default user set. Provider/keys stay Admin. Users do not configure Web Research.

---

## ADR-191 — Users get image and file chat

**Решение.** Same attachment and Storage-upload path as Owner, scoped to `user_id`. Vision uses Default User Conversation AI, never Owner AI as fallback.

---

## ADR-192 — Persistent user files are private by user_id

**Решение.** `StoredFile` queries always include authenticated `user_id`. No cross-user public_id access.

---

## ADR-193 — No Storage page for users

**Решение.** Files in chat + AI retrieval tools. `/jarvis/storage` remains owner-only. No `/chat/storage`.

---

## ADR-194 — Users get Voice Runtime and Orb

**Решение.** Capability `voice`. Same `VoiceRuntimeService`, `VoiceSession`, `JarvisVoiceOrb`. Routes `/chat/.../voice` alias the same controller as `/jarvis/.../voice`. Session `user_id` must match.

---

## ADR-195 — Users have no Projects

**Решение.** No Projects UI/routes. `get_project_context` stays capability `projects` (owner). Backend deny, not only hidden UI.

---

## ADR-196 — Users have no configurable integrations

**Решение.** No Google/Gmail/GitHub/ElevenLabs/Web Research settings for users. Telegram pairing remains a channel mechanism.

---

## ADR-197 — Users have own memory and General Prompt

**Решение.** Existing memory engine + `user_ai_settings.general_prompt`. Retrieval scoped by `user_id`. Workspace settings drawer edits the user’s own prompt.

---

## ADR-198 — Default User Conversation AI stays separate from Owner AI

**Решение.** `AiConfigurationResolver::resolveConversation` still picks Owner vs User role configs. No fallback to Owner AI.

---

## ADR-199 — `/cabinet` is a compatibility redirect

**Решение.** Canonical user URL is `/chat`. `/cabinet` and `/cabinet/chats/{id}` redirect. Cabinet is not a separate product.

---

## ADR-200 — Frontend capability props are presentation only

**Решение.** Inertia `capabilities` hide chrome. Authorization remains `User::canUseCapability` + ownership queries.

---

## ADR-201 — Gemini STT is a SpeechToTextProvider, not Conversation AI

**Контекст.** Gemini chat and Gemini transcription could be collapsed into one call.

**Решение.** `GeminiSpeechToTextProvider` implements `SpeechToTextProvider`. Audio → text only. Conversation AI remains `ConversationTurnService` → `AiChatGateway`. No transcription through `AiChatGateway`.

**Следствие.** The same Gemini credential family can serve STT and chat as separate code paths.

---

## ADR-202 — Gemini STT reuses the existing Gemini credential

**Контекст.** A Voice-specific Gemini key would duplicate secrets.

**Решение.** Resolve the key from `ai_provider_settings` where `provider = gemini` via `GeminiCredentialResolver`. No `GEMINI_STT_API_KEY`, no plaintext Voice secret.

**Следствие.** Admin Voice/Speech has no Gemini API key field.

---

## ADR-203 — STT provider selection is instance-level Admin infrastructure

**Контекст.** Per-user STT settings would fragment the voice stack.

**Решение.** `voice_settings.stt_provider` / `stt_model` are Admin Integrations settings. Ordinary users do not configure STT.

**Следствие.** Owner and User voice sessions share the same STT adapter; Conversation AI roles stay Owner vs Default User.

---

## ADR-204 — Ordinary users do not configure STT

**Контекст.** Users already have Default User Conversation AI.

**Решение.** Workspace Voice UI stays provider-neutral. No Gemini vendor label for ordinary users.

**Следствие.** Configured STT clears the generic “not configured” notice through existing status/error props.

---

## ADR-205 — Auto language detection is the STT default

**Контекст.** Forced ru/it/en would break code-switching.

**Решение.** Gemini `audioTranscriptionConfig` omits `languageCodes` unless an optional hint is present. No required language Admin setting.

**Следствие.** Language/confidence are nullable on the transcript DTO.

---

## ADR-206 — VoiceRuntimeService remains vendor-neutral

**Контекст.** `if (provider === gemini)` in runtime would leak vendors into Core.

**Решение.** Runtime calls `$this->stt->transcribe(...)`. Manager resolves Gemini / OpenAI / Null.

**Следствие.** Adding another STT vendor does not rewrite turn orchestration.

---

## ADR-207 — OpenAI STT remains optional fallback, not required

**Контекст.** OpenAI is not connected for the current product.

**Решение.** Keep `OpenAiSpeechToTextProvider`. Do not require an OpenAI key for Voice.

**Следствие.** Production path is Gemini STT + ElevenLabs TTS.

---

## ADR-208 — Live STT/TTS/AI validation remains deferred

**Status.** SUPERSEDED as current Voice status by ADR-239. This ADR applied to M23.2 implementation milestone, not to later Owner E2E.

**Контекст.** Owner policy: no live provider smoke in the Gemini STT implementation milestone.

**Решение (historical).** Configured status is local (provider + credential connected + model). No Test Connection during that work.

**Следствие.** Do not treat this ADR as current Voice validation status.

---

## ADR-209 — Users are Owner-created only

**Контекст.** Self-registration would create untrusted accounts on a production personal assistant.

**Решение.** No `/register`, invite flow, or login “Create account”. Owner creates `role=user` from Admin. Unknown email cannot self-provision. Unknown Telegram access code cannot create a User.

**Следствие.** User catalog is an Owner operations surface, not a public signup product.

---

## ADR-210 — Disable is preferred over hard delete

**Контекст.** A user owns conversations, messages, memories, files, attachments, reminders, voice sessions, and channel identity.

**Решение.** Canonical statuses `active` / `disabled`. Disable blocks login, sessions, `/chat`, Voice, Telegram AI, and reminder delivery. Data is kept. Hard delete is not a normal User Card action.

**Следствие.** Accidental cascade destruction is out of ordinary user management.

---

## ADR-211 — Owner can reset a user password but cannot recover it

**Контекст.** Support needs a reset path without storing recoverable secrets.

**Решение.** Owner sets a new password (hash only). The value is never redisplayed after save. Ordinary users may change their own password with current + new + confirmation.

**Следствие.** If Owner needs to tell the user a password, they use the value they just typed.

---

## ADR-212 — access_code is Telegram pairing only

**Контекст.** Confusing pairing codes with web passwords would leak channel pairing into login.

**Решение.** Web login is email + password. `access_code` is unique, human-readable, never `2000` for generated users, and is shown on the Owner User Card.

**Следствие.** Regenerating a code changes future pairing, not the web password.

---

## ADR-213 — Regenerating access_code does not unlink Telegram

**Контекст.** Support may rotate a leaked pairing code without kicking an already-linked Telegram.

**Решение.** Regenerate writes a new unique code. Existing `channel_identities` row stays until Owner explicitly unlinks.

**Следствие.** Unlink and regenerate are separate actions.

---

## ADR-214 — Impersonation is Owner-only and session-scoped

**Контекст.** Owner must inspect `/chat` as a user without knowing that user’s password.

**Решение.** Session keys store original Owner id, target id, and started_at. `Auth::login(target)` for the duration. No impersonation table. Structured logs record ids only.

**Следствие.** Exit restores that Owner (or login if Owner context is invalid). Cannot exit into an arbitrary account.

---

## ADR-215 — Impersonation uses effective user permissions, no Admin bypass

**Контекст.** Logging in as the user must not leak Owner Admin because the original identity is Owner.

**Решение.** While impersonating, `isOwner()` is false. `/dashboard`, `/settings`, `/projects`, integrations remain Owner-gated. Banner is required. Writes mutate the target user’s data.

**Следствие.** Diagnostics (User Card) and acting as the user (impersonation) stay distinct.

---

## ADR-216 — User Card diagnostics and impersonation are distinct

**Контекст.** Injecting Owner into a user conversation would mix admin inspection with user history.

**Решение.** User Card chat list is read-only metadata. Memory diagnostics remain Owner-only read. To use the workspace, Owner uses Open as User.

**Следствие.** Owner is not silently added to user conversations.

---

## ADR-217 — Cross-user isolation is enforced backend-side

**Контекст.** Hidden UI is not isolation.

**Решение.** Conversation, attachment, file, memory, voice, reminder, Telegram, and General Prompt paths authorize the effective authenticated `user_id`. Integer id, public id, and Cabinet aliases cannot bypass scope.

**Следствие.** IDOR checks are server-side even if a client crafts a URL.

---

## ADR-218 — Manual A/B isolation validation is required before USER SPACE = MANUAL PASS

**Контекст.** Code review cannot replace two real users exercising chat, memory, files, Telegram, disable, and impersonation.

**Решение.** Checklist exists in [USER_ADMINISTRATION.md](USER_ADMINISTRATION.md). This milestone does not create production test users and does not run the campaign.

**Следствие.** Status remains IMPLEMENTED / NOT VALIDATED until Owner completes A/B.

---

## ADR-219 — Sole Owner cannot be disabled or demoted through ordinary user management

**Контекст.** Accidental disable of the only Owner would lock the instance.

**Решение.** User Card cannot disable, delete, demote, reset password, or regenerate the reserved Owner access code. Created accounts are always `role=user`. Role is not on the common edit form.

**Следствие.** Owner protection is backend-enforced, not only hidden buttons.

---

## ADR-220 — Voice mode auto-starts after an explicit Voice gesture

**Status.** Superseded for capture behavior by ADR-256. The gesture still primes microphone/AudioContext and starts the session, but recording waits for push-to-talk.

**Контекст.** Push-to-talk required a second mic click after entering Voice.

**Решение.** Text→Voice is the user gesture: prime `getUserMedia` + AudioContext, then auto-create the session and enter listening.

**Следствие.** An “Enable microphone” CTA is only for permission/activation recovery, not the normal path.

---

## ADR-221 — No push-to-talk in normal Voice UX

**Status.** Superseded by ADR-256.

**Контекст.** Natural conversation cannot require tap-to-send between turns.

**Решение.** Remove Start listening / Send utterance. Turns finalize via local VAD.

**Следствие.** The old dual-mic control is gone.

---

## ADR-222 — One mic button means mute/unmute only

**Контекст.** Two mic icons (record vs mute) were confusing.

**Решение.** Mic = listening enabled; MicOff = muted. Mute discards unsent audio and does not STT.

**Следствие.** Interrupt remains a separate Square control for speaking/thinking.

---

## ADR-223 — End-of-turn is local bounded VAD

**Status.** Superseded by ADR-256 for the current Web client.

**Контекст.** Vendor VAD or streaming STT would change the runtime contract.

**Решение.** Client-side amplitude VAD (`VoiceTurnDetector`) with `endSilenceMs = 850` and related bounds in `voiceTurnDetection.js`. No cloud VAD. No Gemini Live in this milestone.

**Следствие.** Short pauses do not end a turn; no-speech audio is never uploaded.

---

## ADR-224 — Voice returns to listening after TTS

**Status.** Partially superseded by ADR-256: the runtime returns to listening-ready state but does not automatically capture.

**Контекст.** After speaking, users should continue without clicking.

**Решение.** When playback `ended`, `listen` if needed and start a fresh capture/VAD cycle. Conversation stays open until mute, End, Text, or a fatal error.

**Следствие.** Do not STT Jarvis TTS: no MediaRecorder during `speaking` except barge-in.

---

## ADR-225 — Raw browser MIME is canonicalized before provider validation

**Контекст.** `audio/webm;codecs=opus` plus a hardcoded `utterance.webm` filename caused container/MIME mismatch and `voice_audio_format_unsupported`.

**Решение.** `VoiceAudioMime` strips codecs, maps aliases, and picks a filename extension that matches the container. Workspace exposes STT-supported recorder candidates. Validation uses uploaded-file MIME plus safe client canonical MIME.

**Следствие.** Frontend actions must follow the server state machine (`resume` → idle, then one `listen`). Invalid-state races fetch a snapshot and recover without a full reload.

---

## ADR-226 — No continuous audio archive; streaming STT is future

**Контекст.** Hands-free must not mean always-on vendor streaming or retained mic audio.

**Решение.** Only the current utterance buffer is kept. After upload, browser blobs may GC; server temp files follow existing deletion. Gemini Live / streaming STT is a later latency optimization, not M24.1.

**Следствие.** Mute or Text is how the user stops being listened to. No wake word.

---

## ADR-234 — VAD uses calibrated hysteresis, not a visual RMS gain

**Контекст.** Owner live M24.1: Voice stayed Listening. Ambient microphone RMS (after `* 3.2` visual gain) sat above `speechThresholdMin`. `adaptNoise` only ran below that threshold, so the floor never learned. In `speech_active`, `level >= threshold` cleared `silenceStartedAt` forever.

**Решение.** VAD uses unamplified `rawInputRms`. Orb keeps a separate visual gain. Each listen cycle calibrates ambient (~650ms) without stopping MediaRecorder. Speech starts above `startThreshold`; end-of-turn uses a lower `endThreshold`. `endSilenceMs` remains 850.

**Следствие.** Constant room noise becomes baseline. Short pauses still do not end a turn. `?voice_debug=1` is for the next live tuning.

---

## ADR-227 — Assistant personalization is separate from General Prompt

**Контекст.** Concatenating onboarding into `general_prompt` loses structured identity, status, and header name.

**Решение.** `user_assistant_profiles` holds name, personality, interaction style, `about_user`, and onboarding status. General Prompt stays independently editable.

**Следствие.** Chat tools update profile fields; they do not rewrite General Prompt unless the user asked to change that separately.

---

## ADR-228 — Personalization profile is per-user

**Контекст.** Owner, User A, and User B must not share assistant identity.

**Решение.** Unique `user_id` on `user_assistant_profiles`. Tools resolve the conversation user only. No `user_id` argument from the LLM.

**Следствие.** Impersonation shows/changes the impersonated user’s profile.

---

## ADR-229 — Onboarding is conversational in the normal Conversation Engine

**Контекст.** A form wizard would be a second product surface.

**Решение.** **Познакомиться** opens a normal **Знакомство** conversation. Chat is not blocked if onboarding is incomplete.

**Следствие.** Telegram/Voice reuse the same profile; the Telegram bot username is not renamed.

---

## ADR-230 — Structured profile updates use scoped Core tools

**Контекст.** The model must not invent preferences or complete onboarding from raw chat guesswork.

**Решение.** `update_assistant_profile` writes only explicit user-provided fields. `complete_assistant_onboarding` requires name, personality, interaction style, and `about_user`. Writes do not need a confirmation modal.

**Следствие.** `about_user` complements Memory; it does not replace Memory.

---

## ADR-231 — Assistant name is user-specific presentation identity

**Контекст.** Ordinary users should see their chosen name, not the product brand.

**Решение.** `/chat` header uses `assistant_name` (fallback Assistant). Owner `/jarvis` remains Jarvis.

**Следствие.** Compact identity is injected every turn after platform/role config and before General Prompt.

---

## ADR-232 — Reminders panel is a view over the existing Reminder Engine

**Контекст.** Users need to see and cancel reminders without a separate dashboard.

**Решение.** Shared lazy-loaded drawer on `/jarvis` and `/chat`. GET list + cancel owned future reminders. Creation stays conversational. Active count is a cheap workspace prop.

**Следствие.** Delivery remains Telegram-only. Recurrence is still unsupported. Foreign reminder ids are not accessible.

---

## ADR-233 — Users change personalization later through ordinary chat

**Контекст.** After onboarding, name and style must remain editable.

**Решение.** Ordinary chat keeps `get_assistant_profile` and `update_assistant_profile`. Settings drawer shows current values read-only.

**Следствие.** No second settings product for personality.

---

## ADR-235 — Desktop client cancelled

**Контекст.** A separate Desktop client (Tauri, JARVIS-Desktop, tray, global hotkey, native shell) was planned as a Phase 3 native client. The Personal Workspace already runs in the browser with text, voice, storage, web research, and reminders.

**Решение.** Desktop client is **CANCELLED**. Do not plan Tauri, JARVIS-Desktop, system tray, global hotkey, desktop-specific native shell, or a Desktop auth/API lifecycle. Desktop is not a prerequisite for future features. `Docs/CLIENTS/DESKTOP_APP.md` is removed.

**Следствие.** Supersedes ADR-091, ADR-093 (Desktop half), and Desktop parts of ADR-006/088/090/170. Web Personal Workspace is the primary interactive client (ADR-236).

---

## ADR-236 — Web Personal Workspace is the primary interactive client (JARVIS / LAVR Phase 1 CURRENT)

**Superseded for TARGET rich UI by ADR-268.** CURRENT shipped UI remains `/lavr`. See [INTERFACES.md](INTERFACES.md).

**Контекст.** Multiple clients were sketched (Cabinet, Desktop, Mobile, Telegram).

**Решение.** Primary interactive application is **Web Personal Workspace**. Owner: `/jarvis`. User: `/chat`. `/cabinet` is compatibility redirects only. Telegram is a secondary messaging channel/adapter. Voice is a modality of Web Workspace, not a separate client.

**Следствие.** Architecture diagrams have no Desktop node. Product docs use Workspace wording, not Cabinet-as-product.

---

## ADR-237 — Mobile is an optional future companion, not current priority

**Контекст.** Mobile (Flutter, JARVIS-Mobile) was listed as a near-term native client alongside Desktop.

**Решение.** Mobile remains a possible future companion to Web/Core. It is **not** a new Core and **not** the next required stage. Potential value: push, voice on the go, camera, share-to-Jarvis. Source of truth remains Jarvis backend. No dependency on cancelled Desktop.

**Следствие.** [CLIENTS/MOBILE_APP.md](CLIENTS/MOBILE_APP.md). Do not block Core roadmap on Mobile.

---

## ADR-238 — Versioned Client API is deferred

**Контекст.** Client API was motivated by Desktop/Mobile sharing a protocol.

**Решение.** Do **not** build a versioned Client API merely because Desktop was planned. Keep it as a future requirement if/when Mobile development begins or an external first-party client genuinely needs it. Web uses existing Laravel/Inertia/session routes.

**Следствие.** [CLIENTS/CLIENT_API.md](CLIENTS/CLIENT_API.md) is DEFERRED / NOT CURRENT PRIORITY.

---

## ADR-239 — Basic hands-free Voice is production MANUAL PASS

**Status.** Historical validation of the STT/Core/TTS pipeline. Current capture UX is ADR-256 push-to-talk.

**Контекст.** M23–M24.1.1 implemented Voice Runtime, Gemini STT, Orb UI, local VAD, silence hotfix. Older ADRs deferred live validation.

**Решение.** Owner confirmed in production: Voice starts; microphone/listening; hands-free turn ends after pause; Gemini STT; Jarvis reply; ElevenLabs TTS; post-VAD hotfix. Therefore M23, M23.2, M24, M24.1, M24.1.1 are **MANUAL PASS**. Basic VAD/hands-free is not future work.

**Следствие.** Phase C (Natural Conversation) is latency, barge-in robustness, working memory — not “add VAD”. Wake word is optional research only, not mandatory. Supersedes ADR-173/208 as current Voice status.

---

## ADR-240 — Reminder existence is independent of Telegram (target)

**Контекст.** ADR-039/046 made Telegram a create-and-delivery prerequisite. That blocks Web-only users and treats Telegram as the reminder domain.

**Решение.** **Target:** Reminder is a Core domain object. Creating a reminder must not require Telegram. Telegram is an optional delivery adapter. Other future adapters: Web Workspace, Web Push, Mobile Push. **Current code still enforces Telegram** for create and delivery (ADR-046 still describes runtime).

**Следствие.** Next executable milestone M25U.3.1. Do not document the target as implemented. Supersedes ADR-039/046 as product architecture.

---

## ADR-241 — Telegram is a reminder delivery adapter, not a prerequisite

**Контекст.** Same as ADR-240.

**Решение.** Delivery channels are adapters under Core scheduler/state. Missing Telegram must not prevent persisting a reminder. Web panel/center is a first-class surface. Web Push is a later transport (ADR-242).

**Следствие.** [REMINDERS.md](REMINDERS.md) separates CURRENT vs TARGET.

---

## ADR-242 — Web Push is a future notification transport

**Контекст.** After reminders work in Web without Telegram, users will need in-browser alerts.

**Решение.** Web Push / browser notifications were **future** Phase B work at M25U.3.1. Implemented in Phase B.1 (ADR-258).

**Следствие.** Superseded by ADR-258.

---

## ADR-243 — Tasks are separate from Reminders

**Контекст.** Time-aware Jarvis needs both “notify me when” and “what I need to accomplish.”

**Решение.** Reminder = when Jarvis should notify. Task = what the user needs to accomplish (status, deadline, subtasks, related conversation/project/files, optional linked reminders). Do not collapse Tasks into reminders. No Task tables in M26D.

**Следствие.** [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md). Phase B.

---

## ADR-244 — Proactivity is event/condition driven and bounded

**Контекст.** “Proactive assistant” can be read as unsolicited chatter.

**Решение.** Proactivity is driven by deadlines, reminders, tasks, calendar events, monitored external events, explicit opt-in, and meaningful detected changes. It requires anti-spam rules, user controls, auditability, permissions, and a clear trigger source. Not generic AI chatter.

**Следствие.** Phase E. [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md).

---

## ADR-245 — Personal Knowledge Graph is a future optional structured layer

**Контекст.** Memory Engine already stores facts from conversations. A graph of Person/Company/Project/Event/Task/File/Conversation/Reminder is attractive but premature as a replacement.

**Решение.** Knowledge Graph is a **future optional** structured layer over existing memory and raw sources. Provenance must trace to source data. Do not replace the Memory Engine prematurely. Not an immediate implementation commitment.

**Следствие.** Phase E. People/Contacts intelligence is the same future direction, not current schema.

---

## ADR-246 — Telegram Voice Replies reuse Jarvis Core; no second AI

**Контекст.** Telegram users may want native voice messages. That must not fork a second assistant or Voice Core.

**Решение.** Telegram Voice Reply is adapter **delivery**: Conversation Engine produces assistant text as today; optional TTS then `sendVoice`. Same `user_id`, `conversation_id`, Memory, tools, Assistant Profile, General Prompt. Not a `voice_sessions` Web runtime unless a later implementation has a specific reason.

**Следствие.** [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md). Web Voice MANUAL PASS does not implement this path.

---

## ADR-247 — Assistant text is canonical; audio is a delivery representation

**Контекст.** TTS output could be mistaken for the source of truth.

**Решение.** Persisted assistant **text** is the canonical conversation content. Generated audio is only a transport representation of that text. History, Memory, and Web Workspace use text.

**Следствие.** Voice delivery failure must still leave the text answer intact (ADR-251).

---

## ADR-248 — Telegram Voice Replies reuse the existing TTS abstraction

**Контекст.** A Telegram-only TTS stack would duplicate ElevenLabs/provider config.

**Решение.** Reuse `TextToSpeechManager` / `TextToSpeechProvider`. Do not add a Telegram-specific TTS provider in the target architecture. TTS Voice ID remains instance-level unless a later ADR introduces per-user voice selection.

**Следствие.** Implementation converts provider bytes (often MP3 today) if Telegram requires another container. The instance-level Voice ID clause is superseded by ADR-257.

---

## ADR-249 — Generated Telegram voice audio is not archived by default

**Контекст.** Permanent voice archives conflict with current Voice privacy (transcripts persist; recordings do not).

**Решение.** Temporary TTS artifact → deliver → delete after a bounded retry window. No default archive. A future archive needs a separate explicit decision.

**Следствие.** Same philosophy as `VoiceTempAudioStore` / `jarvis:voice:cleanup-temp`.

---

## ADR-250 — Telegram response medium is a user/channel preference

**Контекст.** Personality and delivery channel are easy to conflate.

**Решение.** Conceptual `telegram_response_mode`: `text` | `voice` | `auto`. Per-user (or user+Telegram-channel) delivery preference — not AI provider config, not `user_assistant_profiles` unless an implementation audit says otherwise. `auto` is a recommended default candidate (voice-in → voice-out; text-in → text-out); exact default at implementation time. Explicit chat commands update this structured preference, not an unreliable Memory fact.

**Следствие.** Assistant name/personality stay shared across channels.

---

## ADR-251 — Voice delivery failure falls back to text

**Контекст.** TTS, conversion, or Telegram media APIs can fail independently of Conversation AI.

**Решение.** If TTS is unavailable, conversion fails, Telegram rejects audio, or temp media fails, send the canonical text. Do not drop the assistant answer.

**Следствие.** Large or non-spoken payloads (code, tables, files) should prefer text/file delivery rather than forced TTS.

---

## ADR-252 — Telegram-native voice UX uses sendVoice

**Контекст.** Sending an audio *file* is a different Telegram UX from a voice bubble.

**Решение.** Target native voice-message UX via Bot API `sendVoice`. Preferred container: OGG / OPUS where Telegram requires it. ffmpeg is a **likely** conversion tool, not a committed shipped dependency until the implementation milestone audits provider output and the host.

**Следствие.** Do not install ffmpeg or change TTS in a documentation task.

---

## ADR-253 — Telegram Voice Replies are a Telegram adapter enhancement

**Контекст.** Channel voice can be misread as a new primary client or a Desktop prerequisite.

**Решение.** This is a **Telegram DM** enhancement. Web Personal Workspace remains the primary interactive client (ADR-236). Desktop remains CANCELLED (ADR-235). Telegram remains a secondary messaging adapter. The milestone is small and independent, after reminder hardening (M25U.3.1), not a new major phase.

**Следствие.** [ROADMAP.md](ROADMAP.md) Phase C; [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md).

---

## ADR-254 — Telegram Voice Replies send MP3 via sendVoice; default text

**Контекст.** Docs previously treated OGG/OPUS + ffmpeg as likely. Telegram Bot API `sendVoice` accepts OGG/OPUS, MP3, and M4A. Existing ElevenLabs TTS already returns MP3.

**Решение.** MVP sends ElevenLabs MP3 bytes with Nutgram `sendVoice`. No ffmpeg. Preference lives in `user_channel_preferences` (not Memory / General Prompt / assistant profile). Default mode is **text** so deploy does not change Telegram behavior. TTS Voice ID remains instance Voice settings. Delivery failures fall back to a single `sendMessage`.

**Следствие.** Owner confirmed a live voice bubble (MANUAL PASS). Telegram Voice Input is a separate adapter inbound path (ADR-255). The instance-level Voice ID clause is superseded by ADR-257.

---

## ADR-255 — Telegram Voice Input reuses Gemini STT; DM voice notes only

**Контекст.** Paired DM previously rejected non-text. Web Voice already has a working Gemini STT provider. A second Telegram-specific transcriber or `voice_sessions` path would split Core.

**Решение.** Telegram Voice Input is adapter inbound: `Message.voice` in a paired private DM → Nutgram `getFile`/`downloadFile` → existing `SpeechToTextManager` / `GeminiSpeechToTextProvider` → transcript → `ConversationTurnService` → existing `TelegramReplyDeliveryService` with explicit `inboundModality=voice`. Same identity, active-user, and `channel_message_id` idempotency as text. Application limits stay at Web STT bounds (30 s / 2 MB), not the Telegram 20 MB `getFile` ceiling. Typical OGG/Opus is accepted without ffmpeg. Groups, video notes, audio files, and documents are unchanged. Empty transcript and STT errors send text only and do not create an AI turn. Gemini model/credentials are unchanged.

**Следствие.** `auto` response mode becomes voice-in → voice-out and text-in → text-out. Status IMPLEMENTED / NOT VALIDATED until Owner manual checklist.

---

## ADR-256 — Web Voice capture is push-to-talk only

**Контекст.** Hands-free «Диалог» stopped reliably producing audible voice replies and complicated capture/playback state. The requested product behavior is an explicit radio interaction.

**Решение.** Remove the hands-free mode and automatic VAD capture from normal Web Voice. Keep one «Рация» flow: pointer hold starts recording; release/cancel/lost capture finalizes the turn; hard max duration remains. Pressing push-to-talk during speaking/thinking interrupts before recording. After TTS the session becomes listening-ready but waits for the next hold.

**Следствие.** Supersedes ADR-220/221/223/224 where they require automatic capture or silence-finalized turns. The same Voice Runtime, Gemini STT, Conversation Engine, persistence, and ElevenLabs TTS remain.

---

## ADR-257 — TTS voice is a per-user cross-channel preference

**Контекст.** A singleton ElevenLabs Voice ID made every person share one voice. Users need to select their own voice without changing anyone else.

**Решение.** Curate six currently available voices: Jessica, Sarah, Lily; Eric, George, Chris. Store the selected ID in nullable `users.voice_id`, validate it against `ElevenLabsVoiceCatalog`, and expose selection in each user's Workspace settings. Resolve the user explicitly at Web Voice and Telegram delivery boundaries and pass the existing optional Voice ID into `SpeechSynthesizer::synthesize`. Provider/API key/STT settings remain instance-level and owner-only; the instance Voice ID is fallback only.

**Следствие.** One user's choice applies to both Web Voice and Telegram TTS and cannot alter another user. Supersedes the instance-only Voice ID clauses in ADR-248/254. No `user_voice_settings` table and no request-global `Auth::user()` inside TTS providers.

---

## ADR-258 — Reminders 2.0: Core lifecycle, per-channel delivery, Web Push

**Контекст.** M25U.3.1 made reminder create channel-independent with Telegram as an optional adapter. Users still needed browser notifications, a real Reminder Center, edit/snooze/done, and recurrence without collapsing into Tasks.

**Решение.** Reminder remains the Core object. Telegram and Web Push are independent adapters with `reminder_deliveries` per channel. Missing channels is not a Core failure. Done (`completed`) is distinct from Delivered. Recurrence is a simple token (`daily`/`weekdays`/`weekly`/`monthly`) on the same row plus `reminder_occurrences` history; next fire uses local wall clock so DST keeps e.g. 09:00 Europe/Rome. Web Push uses instance VAPID env keys (`minishlink/web-push`), `push_subscriptions` owned by the authenticated user, and `/reminder-sw.js`. Permission only after a user gesture. Push payload is bounded and allowlisted. Tools: list/update/snooze/complete/cancel plus create; ambiguous matches do not mutate.

**Следствие.** Supersedes ADR-242 (Web Push is no longer future). Owner later confirmed the live core flow: MANUAL PASS for Web Push, Reminder Center, and basic Reminder 2.0 usage (ADR-259). Tasks, Notification Center, Daily Brief are Phase B.2 (ADR-260). [REMINDERS.md](REMINDERS.md).

---

## ADR-259 — Reminders 2.0 live core flow

**Контекст.** Phase B.1 shipped Web Push and Reminder Center v2. Owner reported the live flow works.

**Решение.** Record **MANUAL PASS for confirmed live core flow** only: Web Push, Reminder Center, basic Reminder 2.0 user flow. Do not claim exhaustive MANUAL PASS for DST, recurrence edge cases, multi-device, or delivery-failure paths unless separately confirmed.

**Следствие.** [REMINDERS.md](REMINDERS.md).

---

## ADR-260 — Tasks, Notification Center, briefs, bounded proactive

**Контекст.** Phase B needed commitments, deadlines, context links, summaries, and important-surface alerts without turning Jarvis into an autonomous external actor.

**Решение.** Tasks are a separate Core table (`tasks`), not reminder rows. Optional `reminders.task_id`. Completing/cancelling a task cancels future open linked reminders and keeps history. Notification Center is `jarvis_notifications` with dedupe keys; Web Push is reused, Telegram is not used for inbox spam. Daily/Evening/Weekly briefs and proactive suggestions are per-user opt-in (default off). Proactive max 3/day, 4h cooldown, deterministic triggers only; LLM may phrase. Ordinary users get personal tasks/inbox/briefs; not Owner Projects/Gmail/Calendar/GitHub.

**Следствие.** Phase B.2 IMPLEMENTED / NOT VALIDATED. Risky external writes stay on the current confirmation policy. [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md).

---

## ADR-261 — Phase C.1 conversation intelligence on the existing engine

**Контекст.** Long chats needed topic continuity, pronouns, incomplete STT phrases, and safer tool targeting without a second Voice AI, a second memory engine, or a new message schema.

**Решение.** Derive a bounded `WorkingContext` each turn from the recent tail, summary, topics, and compact `tool_execution_logs` ids. One personality presentation for Web/Voice/Telegram. Clarify mutation ambiguity; do not clarify obvious dates. Trusted recent tool ids may bind a pronoun; guessed ids are rejected. Temporary style stays conversation-scoped. Frontend generation tokens suppress stale assistant JSON; the server turn is not cancelled. Failure of this layer falls back to the previous Conversation Engine context path. No migration.

**Следствие.** C.1 IMPLEMENTED / NOT VALIDATED. C.2 Beta (ElevenLabs realtime Web voice) is IMPLEMENTED / NOT VALIDATED. [HUMAN_LIKE_ASSISTANT.md](HUMAN_LIKE_ASSISTANT.md), [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md).

---

## ADR-262 — Phase C.2 Beta ElevenLabs realtime is a parallel Web Voice mode

**Контекст.** Natural conversation needed continuous turn-taking, barge-in, and expressive speech without replacing Jarvis Core or Telegram Voice, and without deleting the working «Рация» PTT path.

**Решение.** Add Web-only «Диалог Beta» beside «Рация» (default). ElevenLabs owns realtime audio transport, STT, VAD, barge-in, and TTS. Jarvis owns conversation, memory, tools, confirmations, and isolation via a Custom LLM adapter that resolves a signed `voice_sessions` row (metadata `provider=elevenlabs_realtime`). Browser never receives the ElevenLabs API key. Telegram must not create a realtime agent session. Feature disabled unless `ELEVENLABS_REALTIME_ENABLED` plus agent id and Custom LLM secret are set. Legacy PTT is not removed until Owner A/B.

**Следствие.** C.2 Beta IMPLEMENTED / NOT VALIDATED. [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md), [CLIENTS/VOICE_UI.md](CLIENTS/VOICE_UI.md).

---

## ADR-263 — Personal Knowledge Layer is an index, not a second Memory Engine

**Контекст.** Jarvis already had Memory, Projects, Tasks, conversation history, and integrations. Phase E needed people/project intelligence and a timeline without a graph database, without replacing Memory, and without autonomous watchers.

**Решение.** Additive relational tables (`knowledge_entities`, aliases, relationships, events, sources, analysis runs). `user_id` is the graph. Projects remain canonical; knowledge may reference `project_id`. Deterministic ingest from Core actions; Analysis AI only for bounded unstructured text. C.1 may retrieve a tiny `knowledge_context` slice. Chat delete detaches provenance and keeps durable knowledge when other sources remain. No Neo4j, no mass backfill, no watchers in E.1.

**Следствие.** E.1 IMPLEMENTED / NOT VALIDATED. E.2 watchers: [WATCHERS_AND_AUTOMATIONS.md](WATCHERS_AND_AUTOMATIONS.md). [KNOWLEDGE_LAYER.md](KNOWLEDGE_LAYER.md).

---

## ADR-264 — Watchers are explicit bounded conditions, not an agent loop

**Контекст.** Users want “when X happens, tell me Y” (Gmail reply, GitHub commit, task still open, calendar moved). An unrestricted autonomous agent, generated code/SQL/HTTP, or silent external writes would violate confirmation, privacy, and anti-spam rules. B.2 already emits heuristic suggestions; Reminders already cover known times.

**Решение.** Persist user-created watchers with a closed trigger/condition/reaction vocabulary. First check establishes a baseline so history does not fire. Source adapters normalize observations; a central evaluator and reaction executor stay provider-neutral. Notify / create task or reminder / bounded analysis / proposed external action only. External writes wait for foreground confirmation. Polling is per-watcher and bounded. Dedup, cooldown, daily caps, and aggregation prevent spam. Auth failures block without hammering. Core is channel-independent; Web Notification Center / Push deliver.

**Следствие.** E.2 IMPLEMENTED / NOT VALIDATED. Phase E is not complete. [WATCHERS_AND_AUTOMATIONS.md](WATCHERS_AND_AUTOMATIONS.md).

---

## ADR-265 — Cross-source synthesis is a derived view, not a second domain

**Контекст.** After E.1 and E.2, Jarvis could store Memory, Knowledge, Tasks, Reminders, Watchers, Projects, and integration observations, but could not answer “what is happening on YFS?”, “who am I waiting on?”, or “what stalled this week?” without dumping search hits. A second graph, dashboard database, or autonomous agent would compete with canonical domains.

**Решение.** Add a read-only `CrossSourceSynthesisService` that builds a bounded FactPack, dedupes by fingerprint, derives waiting-for and explicit commitments from Watchers/Tasks/Knowledge, ranks deterministically, and optionally asks Analysis AI for a narrative. Authoritative domains win on conflict; unresolved conflicts are surfaced. Cache is a short TTL plus per-user version bump — no `waiting_items` migration. Tools-first; tiny `synthesis_context` only when C.1 has an active project, dropped first on overflow. Daily Brief / Weekly Review / B.2 proactive consume synthesis under existing caps. No Gmail/Calendar/GitHub polling inside synthesis. No auto watcher/task/mail.

**Следствие.** E.3 IMPLEMENTED / NOT VALIDATED. Phase E is not complete. Do not invent E.4 by numbering. [CROSS_SOURCE_SYNTHESIS.md](CROSS_SOURCE_SYNTHESIS.md).

---

## ADR-266 — LAVR is single-client, single-CEO

**Контекст.** JARVIS was a multi-user personal assistant platform. This repository is a dedicated instance.

**Решение.** LAVR is one production instance for one CEO. Not SaaS, not multi-tenant, no third-party registration. Laravel `users` remains for auth; product has one working client.

**Следствие.** [PRODUCT.md](PRODUCT.md). Origin multi-user ADRs (e.g. 020, 209–219) are historical.

---

## ADR-267 — Telegram Chat is the primary fast interaction channel

**Контекст.** CEO work happens on the phone. Short questions, voice, alerts, and approve/reject must not require opening Admin or a desktop browser.

**Решение.** Telegram private chat is the primary **fast** channel: conversation, voice, notifications, short briefs, quick actions. It is not the only UI.

**Следствие.** Supersedes “Telegram is secondary” as a **product** ranking (ADR-236 CURRENT UI ranking). Adapter rules (no AI in Nutgram) still hold (ADR-001/002). [INTERFACES.md](INTERFACES.md).

---

## ADR-268 — Telegram WebApp is the primary rich UI

**Контекст.** Chat cannot show People, Projects, Meetings, Commitments, and Today as a full operating picture. A separate frontend would fork the product.

**Решение.** TARGET primary **rich** UI is Telegram WebApp. Use the **same** responsive LAVR Workspace as standalone Web. Do not build a second SPA. Phase 3.

**Следствие.** Supersedes ADR-236 for TARGET rich UI. CURRENT shipped rich UI remains `/lavr` until WebApp ships.

---

## ADR-269 — Standalone web remains available

**Контекст.** Production already serves `https://lavr.youngfashionshow.com`.

**Решение.** Standalone Web Workspace stays. Same core and, TARGET, same frontend as WebApp. Admin remains a separate technical surface.

**Следствие.** [INTERFACES.md](INTERFACES.md).

---

## ADR-270 — AI is not the source of truth

**Контекст.** Models hallucinate, truncate, and mix intents. JARVIS automations sometimes asked the user to re-run a query or dumped technical text.

**Решение.** AI understands, classifies, analyzes, synthesizes, and talks. It does not own operational truth.

**Следствие.** [PRODUCT.md](PRODUCT.md).

---

## ADR-271 — Operational facts live in a structured database

**Контекст.** Memory and Knowledge search cannot reliably answer “what did Kolya promise?” or “what is happening on Chicago?”.

**Решение.** People, organizations, projects, meetings, commitments, tasks, decisions, events, watchers, and scheduled reports are (TARGET) structured records. Knowledge/Memory are supporting. Semantic search is not the primary lookup for operational facts.

**Следствие.** [DOMAIN_MODEL.md](DOMAIN_MODEL.md). CURRENT: Knowledge entities + synthesis stand in until Phases 4–6.

---

## ADR-272 — Unified Person model with roles

**Контекст.** Splitting employees/clients/partners into separate root tables fragments identity.

**Решение.** One `people` entity. Multiple roles per person. No rigid single `type` as the data model.

**Следствие.** [PEOPLE_AND_RELATIONSHIPS.md](PEOPLE_AND_RELATIONSHIPS.md). Phase 4.

---

## ADR-273 — Employees extend Person

**Контекст.** Staff need position, manager, ownership — clients do not.

**Решение.** `employee_profiles` extend `people`. An employee is still a Person.

**Следствие.** [PEOPLE_AND_RELATIONSHIPS.md](PEOPLE_AND_RELATIONSHIPS.md).

---

## ADR-274 — Projects are first-class business contexts

**Контекст.** The CEO runs multiple shows and mailboxes. Word search for “Chicago” is not a project briefing.

**Решение.** Evolve `projects` into business contexts that bind people, orgs, meetings, mailboxes, groups, commitments, tasks, decisions, reports. Keep one project system (extend existing table).

**Следствие.** [PROJECTS.md](PROJECTS.md). ADR-041 (Project ≠ Topic) still holds.

---

## ADR-275 — Meetings are structured objects

**Контекст.** Transcripts dumped into Knowledge lose participants, decisions, and commitments as operational facts.

**Решение.** `meetings` store transcript as source of truth for words plus structured extraction. Zoom transcript is not “just a document”.

**Следствие.** [MEETING_INTELLIGENCE.md](MEETING_INTELLIGENCE.md). Phase 5.

---

## ADR-276 — Commitments are first-class objects

**Контекст.** Synthesis `list_commitments` is derived from knowledge events/tasks. That is not tracking with evidence.

**Решение.** Commitment = a person’s promise. Distinct from Task. Auto-extract; statuses include likely_done vs confirmed; evidence closes.

**Следствие.** [COMMITMENTS.md](COMMITMENTS.md). Phase 6. ADR-243 (Tasks ≠ Reminders) unchanged.

---

## ADR-277 — Automation execution is deterministic

**Контекст.** Scheduled reports became reminders or subject lists; watchers were mis-created.

**Решение.** After a structured automation object is persisted, the engine evaluates scheduler/watcher/event/condition/match/notify without re-interpreting natural language each cycle.

**Следствие.** [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md). Phase 7. ADR-264 still describes CURRENT watchers.

---

## ADR-278 — Conversational AI parses intent but does not own scheduling

**Контекст.** The model must not invent a new plan every morning for an already saved report.

**Решение.** Conversation turn → structured action JSON → persist. Delivery and checks belong to the Automation Engine.

**Следствие.** [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md).

---

## ADR-279 — Proactive tracking is allowed

**Контекст.** A Chief of Staff that only answers when asked fails Accountability.

**Решение.** Detect → Track → Notify → Suggest are in-product. Chaos is not: caps, validation, attention-reduction. Execute (including third-party messages) is a separate, stricter gate (ADR-280).

**Следствие.** [COMMITMENTS.md](COMMITMENTS.md), [EXECUTIVE_BRIEF.md](EXECUTIVE_BRIEF.md). CURRENT proactive heuristics remain bounded (ADR-244/260).

---

## ADR-280 — External actions toward third parties require policy or permission

**Контекст.** Auto-emailing staff after a missed deadline can harm relationships and trust.

**Решение.** Default: ask the CEO (“Напомнить Коле?”). Automatic writes to third parties only with explicit automation policy. Existing ToolConfirmationPolicy for Gmail/Calendar/GitHub writes remains.

**Следствие.** [AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md), ADR-065/075/082.

---

- Алфавит generated access_code (кроме зарезервированного 2000).
- 403 vs redirect когда user открывает admin URL.
- Auth схема future Mobile (token flavour) — only if Mobile is built; Desktop auth cancelled.
- Realtime native voice transport (WebRTC vs WebSocket streaming) beyond current HTTP utterance blobs.
- Optional long-term audio recording retention.
- Набор service updates (`my_chat_member`) beyond bot left/kicked/member/admin/restricted.
- Retention raw messages по закону/желанию пользователя (отдельно от derived lifecycle).
- UX явного переноса group knowledge → personal fact.
- Persisted capability overrides (сейчас достаточно default из role).
- Retention `tool_execution_logs`.
