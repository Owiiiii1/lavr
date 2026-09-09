# Архитектура памяти

> **CURRENT Memory Engine** (JARVIS “Phase 2” in this file means origin memory work, **not** LAVR documentation Phase 2). Operational facts TARGET: [DOMAIN_MODEL.md](DOMAIN_MODEL.md). Memory ≠ Knowledge ≠ People.

Ключевой документ **личной памяти**. Реализация memory engine уже в коде. Контракты и raw storage закладывались в origin Phase 1.

---

## Почему нельзя кормить модель всей историей

Постоянный личный ассистент накапливает годы сообщений. Если каждый запрос склеивать в один prompt:

- стоимость и latency растут линейно;
- окно модели заполняется шумом;
- релевантные факты тонут в бытовых репликах;
- смена темы «заражает» ответ чужим контекстом;
- схема не масштабируется.

Personal memory retrieval is always scoped by the current `user_id`. M25U.2 does not add a new memory engine. User A never receives Owner or User B memory. Owner User Card memory diagnostics remain a separate read-only admin path (`UserMemoryController`). Workspace Settings → Memory shows counts (facts/topics, last analysis) for the current user, not raw internal tables. Workspace Settings → **Knowledge** is a separate section (entities / people / projects / recent activity). Impersonation uses the target user’s memory and knowledge because Auth is that user.

**Knowledge Layer (E.1)** is not Memory. Memory stores durable remembered facts. Knowledge stores structured entities, relationships, and timeline events with provenance. Knowledge may be derived from Memory; Memory is never deleted because Knowledge exists. See [KNOWLEDGE_LAYER.md](KNOWLEDGE_LAYER.md).

---

## Raw history vs derived memory

| Слой | Что это | Можно пересчитать? | Можно удалить автоматически? |
| --- | --- | --- | --- |
| Raw messages | Точный текст/метаданные входящих и исходящих | Нет (это факты канала) | **Нет** — не уничтожаются только потому, что появился summary или memory |
| Derived memory | topics, facts, summaries, classifications, embeddings | Да | Да, с возможностью rebuild |

Derived memory — производный слой. Это позволяет:

- переиндексировать память;
- чинить ошибки классификации;
- менять AI-модели;
- перестраивать memory engine без потери исходных данных.

**Принцип:** raw messages никогда не удаляются автоматически из-за появления summary или extracted memory. Политика retention (юридическая, ручная очистка) — отдельное решение, `TBD`, и не смешивается с lifecycle derived-слоя.

Явное удаление **личного чата из Workspace** — отдельное действие пользователя, не memory-engine lifecycle. Оно удаляет raw messages **этого** conversation. Подтверждённые/derived `memories` **не** стираются: `memory_sources` теряют conversation/message/summary ссылки, факт остаётся. Knowledge provenance for that chat is detached the same way (`knowledge_entity_sources.conversation_id` / `message_id` nulled). Entities remain if other sources exist; purely auto-derived orphans are marked `orphan_candidate`, not hard-deleted. Нет продукта «forget» в этом изменении. Conversation summaries и analysis runs этого чата удаляются как child data.

Отдельный слой, не personal memory:

- **Ephemeral media summaries** — `message_attachments.summary_text`. Derived visual metadata so Jarvis can remember what a screenshot showed after the original is purged. Not bulk-ingested into `memories`.
- **Persistent Storage** — `stored_files` + chunks. Owner document library. Retrieval via tools. Never auto-injected into conversation context. See [STORAGE.md](STORAGE.md).

---

## Типы памяти

### 1. Raw conversation history

Полная история сообщений. Phase 1 уже обязана это хранить. Поля уровня: conversation (`kind` direct|group), channel, role, body, timestamps, внешние id канала, для групп — sender и thread/reply.

Личные DM и group raw — один message engine, разные retrieval scopes. Group history не personal memory.

**Cross-chat:** новый chat не получает raw всех старых. В пакет: current raw/recent + relevant **summaries** других чатов того же space + structured memory. Raw другого чата — только targeted retrieval. ADR-036.

### 2. Topic memory

Информация, сгруппированная вокруг темы. Тема живёт дольше одного разговора и **принадлежит owner scope** (user / group / global). Сообщение может относиться к нескольким темам того же scope.

Примеры user topics: Programming, Python, School. Набор user A не виден user B.

### 3. Facts / long-term memories

Утверждения с **scope**, **owner** и **provenance**. Personal user: «учит Python» — обязательно `user_id`. Group knowledge: «в группе X решили сменить API». Global/system knowledge — отдельный scope, не чужие personal facts.

У факта есть confidence, время, source_kind, ссылки на raw messages, возможность revision.

Group knowledge не копируется в personal автоматически. Personal user A не копируется в B.

### 4. User profile

Стабильная информация **конкретного** пользователя и предпочтения. Один профиль на `user_id`. Компактный кандидат в **его** context package.

### 5. Working context / conversation context

Контекст **текущего** чата: последние реплики, активная тема, недавние сущности, trusted recent tool ids, незакрытый clarification, temporary style. Короткоживущий. Не заменяет long-term facts и не переносится сырьём в другой conversation.

Phase C.1 implements this as `WorkingContext` derived each turn from the recent tail, conversation summary, topics, compact tool-log references, and (when indicated) an active project name. It is **not** written into `memories`. Example: “мы сейчас выбираем между XREAL Aura и Meta” stays working context unless the user states a durable preference.

New Chat обнуляет raw/working **этого** чата. Structured memory и summaries других чатов остаются. Raw других чатов не копируется. ADR-017, ADR-036.

### 6. Summaries

Сжатое представление длинных кусков диалога. Служебный слой для retrieval и для людей в диагностике. Не заменяет raw.

---

## Сущности (концептуально)

Имена таблиц могут отличаться; важны роли. Детали связей: [DATABASE.md](DATABASE.md).

- **conversations** — логический диалог (`direct` | `group`); не равен «Telegram chat id» навечно, но group conversation связан с `telegram_groups`.
- **messages** — raw history (личный и групповой в одной таблице).
- **telegram_groups** / **telegram_group_participants** — discovery групп и участники.
- **topics** — устойчивые темы с owner (`user` | `group` | `global`).
- **message_topic_relations** — many-to-many, с весом/confidence классификации.
- **memories** — факты с **owner/scope** (`personal` + `user_id`; conceptual `group_knowledge` / `global/system` later). **M14 group facts are NOT this table** — they live in `telegram_group_knowledge`.
- **telegram_group_knowledge** / **telegram_group_knowledge_sources** / **telegram_group_knowledge_revisions** / **telegram_group_analysis_runs** — M14 Group Analysis. Owner = `telegram_group_id`. Never written by personal memory jobs.
- **memory_topics** — привязка фактов к темам.
- **entities** — Phase E.1 `knowledge_entities` (person / project index / organization / …). Semantic index, not Memory.
- **entity_relations** — Phase E.1 `knowledge_relationships` (controlled types; deactivate rather than delete).
- **knowledge_events** / **knowledge_entity_sources** — timeline + provenance. [KNOWLEDGE_LAYER.md](KNOWLEDGE_LAYER.md).
- **summaries** — сжатия диапазонов messages или topics.
- **user_profile** — стабильный профиль на каждого user.
- **memory_revisions** — история изменений факта (было → стало, причина, source messages).

---

## Анализ входящего сообщения

Система определяет:

- тему(ы): существующие / новая / несколько сразу;
- стоит ли сохранять новую информацию;
- меняет ли она известный факт;
- временная это информация или долговременная.

Примеры:

- «Напомни, как мы решили делать память в Jarvis» → topic `Jarvis` + architectural memories, не весь быт.
- «Завтра в 8 сервис» → временный факт с expiry, не вечный профиль.
- «На самом деле машину забрал» → revision/contradiction предыдущего факта.

Topic classification и memory extraction — **разные** шаги. Их можно делать разными моделями, синхронно или после ответа.

---

## Relevance, confidence, время

Каждая derived-запись несёт как минимум:

- **scope** — personal memories: `personal` + `user_id`. Group derived knowledge is a **separate table** (`telegram_group_knowledge`), not `memories.scope=group_knowledge`.
- **owner** — для `personal` обязателен `user_id`; для group — group id; global — без user
- **source_kind** — `direct_conversation` | `telegram_group` | `summary` | `manual_admin` | иное
- **source_group_id** — если источник группа
- **created_at / updated_at**
- **source_message_ids** (откуда взяли)
- **confidence** (классификатор/экстрактор не уверен на 100%)
- **relevance score** на этапе retrieval (зависит от запроса, не хранится как единственная истина)
- **status**: active / superseded / obsolete / disputed (`TBD` точный enum)
- **valid_from / valid_until** для временных фактов, если известно

Retrieval **сначала** фиксирует scope (`user_id` / group / global). Глобальный поиск «всех memories инстанса» запрещён. ADR-016.

Затем учитывает:

- совпадение topics того же owner;
- свежесть;
- confidence;
- явное supersede;
- working context текущего разговора.

---

## Updates, contradictions, obsolete

- Новый факт, совместимый со старым — update или дополнение + revision.
- Прямое противоречие — пометить старый `disputed`/`superseded`, не удалять raw и не удалять старую memory-строку молча: нужен revision trail.
- Устаревшее по времени (`valid_until`) — не скармливать как актуальное, оставить для истории.
- Низкий confidence — можно хранить, но не класть в prompt без необходимости.

Точные пороги confidence — `TBD` (настраиваемые, не зашитые навечно).

---

## Context package

Перед вызовом LLM собирается пакет примерно из:

1. Platform / System Prompt **выбранного** conversation config (Owner или Default User);
2. Channel / System Rules;
3. **User General Prompt** этого user (не заменяет п.1);
4. релевантные **его** memories;
5. релевантные **его** topics;
6. summary / recent **текущего** conversation;
6b. relevant summaries **других** conversations того же space (не их raw);
7. user profile этого user;
8. current message.

См. иерархию в [USERS_AND_CABINET.md](USERS_AND_CABINET.md). ADR-018.

Не отправлять всю БД. Не отправлять все summaries «на всякий случай».

M12 пакет (порядок в `ConversationContextBuilder` / `ContextBudgetManager`):

1. Platform prompt выбранного Conversation AI;
2. current local time / timezone;
3. tool context (`create_reminder`, assistant profile tools, `search_conversation_history`, …);
4. **Assistant identity** (`user_assistant_profiles`: name, personality, interaction style, `about_user`) — not Memory;
5. User General Prompt;
6. relevant personal memories (labelled system block);
7. compact memory-derived user profile, если есть;
8. relevant summaries **других** chats того же user (не их raw);
9. current conversation summary, если чат длиннее recent window;
10. recent raw **текущего** conversation + current inbound.

`about_user` is a compact onboarding summary. Memory Engine still accumulates individual facts. Do not bulk-copy the onboarding transcript into `memories`. [ASSISTANT_PERSONALIZATION.md](ASSISTANT_PERSONALIZATION.md).

Бюджеты: `config/memory.php` (`max_memories=10`, `max_cross_chat_summaries=5`, `min_confidence=0.65`). Retrieval всегда `WHERE user_id = current user` в SQL, затем ranking. Vector DB нет.

Raw другого чата — только tool `search_conversation_history`.

---

## Pipeline памяти

Личный inbound:

```
incoming personal message
  → persist raw
  → personal memory retrieval (ready derived layer only)
  → context assembly
  → Conversation AI
  → response
  → persist response
  → dispatch background jobs (does not block the user reply)
  → Owner Analysis AI extracts topics/memories
  → incremental conversation summary at threshold
```

Групповой inbound не идёт в этот reply-цикл: persist only. Group Analysis is **manual async** Owner Analysis AI (`AnalyzeTelegramGroupRangeJob` on queue `analysis`) writing `telegram_group_knowledge`. It never writes `memories` / `user_profiles` / personal topics. См. [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

Шаги analysis/extraction после ответа могут быть асинхронными. См. [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md).

---

## Retrieval: сейчас и потом

**Phase 1:** recent window по `conversation_id` **и** `user_id` + timestamps. Другие чаты того же user в raw window не подмешиваются.

**M12:** `PersonalMemoryRetriever` — SQL/hybrid: `user_id` first, затем topic/keyword/`normalized_key`, freshness, confidence, status, `valid_from`/`valid_until`. Disputed/superseded/obsolete и expired не подаются как current truth. Bounded candidate queries, без fetch-all-then-filter. `ContextBudgetManager` additionally caps what actually enters one LLM request.

**Future (не обязательно для MVP):**

- embeddings на messages / memories / summaries;
- semantic retrieval;
- hybrid search (SQL + vector);
- graph-like обход relations между topics/entities.

Отдельная Vector DB **не** обязательная зависимость. ADR-005. Момент введения — `TBD`, когда relational retrieval перестанет справляться с качеством.

---

## Summarization

- Порог: `config('memory.summary_message_threshold')` **или** estimated tokens of the unsummarized range (`context_budget.summary_refresh_tokens`).
- Incremental: previous summary + raw после `to_message_id`. Load of that range is capped. Initial long history — chunk/reduce, не один гигантский prompt.
- Summary text itself is bounded (`context_budget.summary_max_chars`); oversized summaries are recompressed.
- Пишет в `conversation_summaries` (версии; `current` / `superseded`; coverage `from_message_id` / `to_message_id`).
- Raw не трогает. Summary не source of truth.
- Пересчёт: `jarvis:memory:backfill` / `UpdateConversationSummaryJob`. Owner Analysis AI.
- Web-scraped facts are not personal memory. Extract from the user’s own statements only.

---

## Области памяти

Telegram Group history **не** становится автоматически персональной памятью. ADR-012. Personal user A **не** становится контекстом user B. ADR-016.

| Область | Owner | Кто пишет | Кто читает в prompt |
| --- | --- | --- | --- |
| Personal conversation history | `user_id` + `conversation_id` | Conversation path | Только этот чат |
| Personal memory (owner и каждый user) | тот же `user_id` | extraction | Только его чаты |
| Group conversation history | group | Groups persist | Owner admin; Analysis |
| Group knowledge | group | Analysis AI | Owner analysis; не personal prompt обычного user |
| Global / system knowledge | instance | admin / system | Явно |

Пример: «в рабочей группе решили сменить API» → group knowledge. «Ребёнок учит Python» → personal memory этого ребёнка, не владельца и не сиблинга.

Analysis jobs используют **Owner Analysis AI**, не conversation config и не User Conversation AI.

Async Memory / group / attachment jobs classify failures (`AsyncFailureClassifier`). `last_error` is a category code, not a raw provider/prompt dump. Transient errors retry with backoff; missing/stale/auth/safety are terminal. `failed()` keeps domain runs consistent with the queue. Operational CLI: `jarvis:reliability:report`, `jarvis:memory:retry-failed` (dry-run default). See [Docs/Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

---

## Единство личной памяти между чатами и каналами

Personal memory принадлежит `user_id`, не conversation и не каналу.

Telegram DM, Web Workspace и Voice этого user пишут в его messages и читают **его** retrieval. Desktop cancelled. Mobile deferred.

Голосовая реплика после STT — обычный inbound text для **его** памяти.

Несколько чатов одного space делят structured memory и могут подтягивать **summaries**. Сырой history другого чата — только по запросу. ADR-036.

Group knowledge → owner personal prompt только через Group Search/Analysis tool, не auto-merge.

Групповые сообщения — тот же message store с `conversation.kind = group`, другая область retrieval.
