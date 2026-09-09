# Conversation Engine

> **CURRENT turn pipeline.** LAVR is one client (no User Space vs Owner Space as a product). TARGET: Conversation AI parses intent; Automation Engine executes ([AUTOMATION_ENGINE.md](AUTOMATION_ENGINE.md)).

Жизненный цикл личного сообщения. Один engine.

Входящие из Telegram-групп **не** проходят этот reply path: persist + passive monitoring. См. ветку ниже и [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

---

## Нормализованный вход

Channel adapter (или Voice layer) передаёт в Core структуру уровня:

- `channel` (`telegram` / `web`; enum may still list unused `mobile` / `desktop` values);
- `modality` (`text` / `voice`) — голос не отдельный ассистент, не отдельный канал-мозг и не новый `conversation_id`. Web Voice modes: **Рация** (hold/release → blob STT → this engine → HTTP TTS) and **Диалог Beta** (ElevenLabs realtime STT/TTS → Custom LLM adapter → this same engine). Telegram remains voice note → Gemini STT → this engine.
- `external_identity` (telegram user id, app user id, …);
- `conversation_id` или hint: Telegram → `channel_identities.active_conversation_id`; Web Workspace → открытый chat; тот же id на каналах одного space;
- `payload` (текст и/или current-turn image attachments; медиа refs в `message_attachments`);
- `occurred_at`;
- `channel_message_id` для идемпотентности.

Адаптер **не** вызывает LLM.

Web Workspace: `JarvisWorkspaceController` + `PersonalChatSurfaceService` → `ConversationTurnService`. Owner uses `/jarvis`, user uses `/chat`. Same controller methods. Integer conversation id is authorized with `ConversationService::ensureOwned` (`user_id`). Cross-user id/URL access returns 404. Disabled users cannot enter a turn. Telegram handler нормализует inbound (text or DM voice note transcript) и вызывает тот же `ConversationTurnService`, затем `TelegramReplyDeliveryService`. Outbound may be `sendMessage` or `sendVoice` (existing TTS; default text). Not a second engine. [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md). User Conversation AI is `AiConfigurationResolver::resolveConversation` → Default User Conversation AI; never Owner Conversation AI.

Workspace may **delete** an owned personal conversation (`DELETE …/chats/{conversation}`). Confirmation is required in the UI. Backend: `ConversationService::deletePersonal` inside a transaction. Child chat data is removed (messages, conversation summaries, analysis runs, voice sessions, tool confirmations, ephemeral `message_attachments` bytes). Independent entities stay: tasks/reminders (`source_conversation_id` / `source_message_id` → null), projects (pivot detached), persistent `stored_files` (message link dropped), durable `memories` (`memory_sources` conversation/message/summary ids nulled, memory row kept), Knowledge entities/relationships/events (provenance conversation/message ids nulled; row kept if other sources remain). Notification `action_url` that pointed at the deleted chat falls back to the workspace root (query string preserved). Group conversations are not deleted through this path. If the deleted chat was the last personal one, `latestOrDefault()` creates `Основной`. [CLIENTS/WEB_WORKSPACE.md](CLIENTS/WEB_WORKSPACE.md).

Дополнительно для Telegram: `chat_kind` (`direct` / `group`), `telegram_chat_id`, sender fields. Group inbound **не** запускает personal reply path. См. [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

---

## Telegram до pairing (не Conversation Engine)

Системные ответы: `/start` без identity, неверный код. AI не вызывается. Неверный ввод не пишется как normal conversation.

После pairing: conversation **`Основной`**, active. AI greeting (M4) — application event в system prompt, ответ `role=assistant` в `Основной`. Paired `/start` повторно AI не вызывает.

Telegram menu (`/start`, «Чаты», «Новый чат», callback выбора) **не** пишется в semantic conversation raw. Technical `role=system` placeholders (в том числе старые M3 «Сообщение сохранено…») не входят в AI context.

---

## Ветка: личное сообщение vs группа

```
normalize
  → if group:
        discover/register telegram_group
        persist raw (group conversation)
        optional lightweight / async Analysis AI
        do not reply unless group policy says so
  → if direct:
        resolve active conversation того же user_id
        шаги ниже (Conversation Engine + space Conversation AI)
```

---

## Шаги (личный DM / Web / Voice)

1. **Сообщение приходит через channel adapter или Web Workspace.**
2. **Определяется пользователь** — session / `channel_identities` → `users`. Ownership проверяется здесь. Неизвестная Telegram identity **не** входит в этот AI path: pairing в адаптере ([USERS_AND_CABINET.md](USERS_AND_CABINET.md)). Нет auto-create User. Owner и user — один pipeline.
3. **Сохраняется raw message** до вызова модели. Падение LLM не должно терять входящее. `user_id` + `conversation_id` обязательны для personal.
4. **Conversation** — active / указанный id **этого** space. Чужой id отвергается.
5. Intent/topic — scope этого space (Phase 2).
6. Topics только этого space.
7. Context Builder MVP (M4): platform prompt выбранного conversation config + User General Prompt + recent semantic messages **текущего** conversation (лимит 5–40, default 30) + current inbound. Другие чаты / groups / projects / long-term memory **не** добавляются. Technical `role=system` не входят в dialogue. Summary-first retrieval — later.
8. Hierarchy: platform prompt **Owner Conversation AI или Default User Conversation AI** → channel rules → User General Prompt → (7) → message.
9. **Conversation AI этого space.** Owner Analysis AI на DM не вызывается. User никогда не получает Owner Conversation config.
10. **Сохраняется ответ** как raw message роли assistant в ту же conversation.
11. **Ответ отправляется** в исходный канал / workspace stream.
12. **Post-processing** (после или параллельно с отправкой).
13. **Извлекаются потенциальные personal memories** этого user (Phase 2; Owner Analysis AI или позже слот `memory_extraction`).
14. **Обновляются topics / summaries / memory / revisions** в **его** personal scope.

Порядок 12–14 не должен блокировать шаг 11, если это ухудшает latency. Архитектура разделяет sync и async пути.

---

## Synchronous path

Нужно, чтобы пользователь получил ответ:

- identify user + authorize conversation;
- persist inbound;
- resolve conversation **этого** user (или создать пустую);
- retrieve **минимально достаточный** контекст в его scope;
- build package;
- Conversation AI;
- persist outbound;
- send to channel.

Sync retrieve: current recent + summaries + compact memory. Тяжёлый raw-on-demand и group hierarchical analysis — tool/job, не обязательный sync dump.

Web Workspace may send multipart images and persistent text files with the user turn. Empty body is allowed when at least one image or Storage file is present. Screenshots are ephemeral `message_attachments`. Text files become `stored_files` (optionally linked via `message_stored_files`). Neither screenshot bytes nor Storage contents are personal memory. Telegram photo ingestion is not in M22.2; the same attachment rows can be created later.

Vision context: current inbound image bytes only, and only while not purged. Historical attachments become a short screenshot summary placeholder when ready (`[Previous screenshot summary: …]`), never replayed pixels. ADR-118 / ADR-124.

Current-turn Storage files contribute compact metadata + a bounded excerpt when small. Large files are tool-retrieved (`list_storage_files`, `search_storage_file_contents`, `read_storage_file_chunks`). Storage is never auto-injected into every prompt. Content from screenshots, Storage, and the web is untrusted data.

Owner Conversation AI may call `search_web` then `fetch_web_page` (capability `web_research`). Search does not auto-fetch pages. Web text cannot authorize tools. Cite only URLs returned by those tools. [WEB_RESEARCH.md](WEB_RESEARCH.md).

`ContextBudgetManager` trims one request before the provider call: platform and current turn stay; recent history is token-bounded; older history is summary-first; tool results share a global budget. [CONTEXT_BUDGET.md](CONTEXT_BUDGET.md). Tool rounds are capped by `context_budget.max_tool_rounds` (default 8).

Phase C.1 adds a bounded **working context** slice (current topic, recent entities, trusted recent Core tool ids, temporary style) plus a short conversational policy. It is assembled by `WorkingContextBuilder` / `PersonalityPresentationBuilder` and clipped by `ContextBudgetManager`. Failure falls back to the previous context behavior. Mutation tools may bind a **unique trusted** recent task for a pronoun; they still must not guess among several matches.

Phase E.1 may add a bounded **knowledge_context** slice when C.1 names an entity or active project. It is a compact index (few entities, relations, events), never the full graph. Detail stays behind `search_knowledge` / `get_entity` and existing source tools. [KNOWLEDGE_LAYER.md](KNOWLEDGE_LAYER.md).

Phase E.3 is tools-first. `get_synthesis` / `get_project_status` / `get_person_status` / `list_waiting_for` / `list_commitments` answer cross-source questions. A tiny **synthesis_context** slice may appear only when C.1 has an active project (summary, top blockers, waiting, recent changes). It is dropped before knowledge and memories on overflow. Indexed synthesis is not live Gmail/Calendar/GitHub — if the user asks “что сейчас”, use the live integration tool when observations are stale. [CROSS_SOURCE_SYNTHESIS.md](CROSS_SOURCE_SYNTHESIS.md).

Tool policy: **Reminder** = the user must act at a known time (`create_reminder`, e.g. “напомни мне проверить почту”). **Scheduled Report** = at a known time Jarvis collects sources and delivers one report (`create_scheduled_report`, e.g. “каждое утро дай сводку почты”, “каждый вечер в 22 планы на завтра”). **Gmail event watcher** = Jarvis polls Gmail and notifies on matching new messages (`create_watcher` gmail event, e.g. “жди письмо от школы”). **Task** = work item (`create_task`). B.2 proactive suggestions are separate heuristics — do not recreate them as watchers or reports. [WATCHERS_AND_AUTOMATIONS.md](WATCHERS_AND_AUTOMATIONS.md), [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md).

Web Workspace text send: the composer stays usable while a turn is thinking. A newer fetch generation discards a stale previous JSON body so an old assistant reply cannot overwrite the newer turn in the UI. The PHP turn is not aborted; already executed tool writes are not rolled back.

Phase C.2 Beta adds a Web-only Custom LLM adapter (`POST /api/voice/elevenlabs/chat/completions`) that resolves a signed local `voice_session` and calls this same `ConversationTurnService`. ElevenLabs conversation history is transport state, not canonical memory. Assistant streaming into ElevenLabs is the final Core text (tool loop first). Confirmations are unchanged.

---

## Asynchronous / post-processing path

Можно сделать после ответа (очередь/worker, `TBD` технология):

- глубокая topic classification;
- memory extraction и contradiction handling;
- summarization;
- embeddings (когда появятся);
- debug/trace logs;
- пересчёт derived memory.

Ядро должно позволять подключить queue, не требуя её в Phase 1 (достаточно sync no-op или inline post-process).

Групповой analysis — async **Owner Analysis AI** (M14). Results live in `telegram_group_knowledge`, not personal `memories`. Owner personal chat does **not** auto-mix group knowledge. M14 may expose bounded derived facts only through `get_project_context` when a group is attached to a project. M15 Owner Conversation AI calls `search_group_knowledge` explicitly; queued analysis never blocks the personal turn.

---

## Исходящее из админки в группу

Не путать с ответом Conversation Engine.

```
Admin Panel
  → Group Messaging Service
  → Telegram Adapter
  → Bot API
  → persist raw в group conversation
```

LLM здесь не участвует, если администратор просто пишет текст.

---

## Идемпотентность и сбои

- Повтор одного и того же `channel_message_id` не создаёт дубликат raw (уникальность в рамках канала).
- Если LLM упал после persist inbound — можно retry generation, не принимая сообщение заново из Telegram.
- Если send в канал упал после persist outbound — retry send, не генерировать второй ответ без политики (`TBD`: at-least-once vs exactly-once на доставку).

---

## New Chat

Пустая conversation того же space; становится active в Telegram, если создана оттуда. Raw пустой. Summaries других чатов и structured memory остаются. Не «сброс профиля». Не копировать raw старых чатов.

---

## Голос

Тот же User Space, тот же selected `conversation_id`, тот же Conversation Engine (`ConversationTurnService`), тот же AI config space, одна memory. Нет отдельных voice memories и нет auto-created voice chat.

`VoiceRuntimeService` → `ConversationTurnService` → `ConversationAiService`. Direct LLM from Voice Runtime is forbidden.

Final STT transcript = ordinary user `messages` row (`channel=web`, `metadata.modality=voice`, `voice_session_public_id`). Assistant reply = ordinary assistant row. Interrupt after persist does not delete the assistant message (`voice_playback_interrupted`).

Voice uses the same User General Prompt plus an optional bounded spoken-style presentation hint. Same working context, same reference resolution, same clarification policy, same tools and confirmation policy. Same `ContextBudgetManager`: long voice sessions cannot grow an unbounded prompt because transcripts are normal messages. Spoken brevity is presentation only — not a second personality.

Runtime: [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md). Orb UI: [CLIENTS/VOICE_UI.md](CLIENTS/VOICE_UI.md).

---

## Tools / actions

Tool loop в одном turn: несколько последовательных calls. Не `one message = max one tool call`. Hard safety limit: **max 8 tool rounds** (`context_budget.max_tool_rounds`). Runtime also stops earlier on **no-progress** repetition (same tool + args / same result fingerprint / short alternating loop; default 2 consecutive no-progress rounds).

Invariant: every user turn ends in a user-facing response — a useful answer, a partial answer with a natural limitation, or a short unavailable line after recovery is exhausted. Reaching the tool cap or a no-progress stop does **not** produce a generic technical-error fallback. `AgentToolLoop` runs a **forced final synthesis** with tools disabled: original request + bounded collected results + “Do not call tools.” Duplicate identical mutation calls in the same turn are not executed again.

A new user message is a new execution turn. Previous tool plans, retry state, and incomplete loops do not continue. Short presence checks («эй», «ты тут?») disable tools for that turn. «Повтори предыдущий» restates the previous semantic answer; it does not blindly replay a stale tool plan.

Реализовано в Core (`ConversationAiService` + `AgentToolLoop`): AI → tool call(s) → `ToolRegistry` → `ToolExecutionService` (capability + confirmation policy + `tool_execution_logs`) → tool result(s) → AI → possibly more tools or forced synthesis → final answer. Telegram и Web Workspace не знают, какой tool сработал. Future Mobile would use the same Core; there is no Desktop client.

Tools:

- `create_reminder`, `list_reminders`, `update_reminder`, `snooze_reminder`, `complete_reminder`, `cancel_reminder` — Reminder Engine. [REMINDERS.md](REMINDERS.md).
- `create_task`, `list_tasks`, `get_task`, `update_task`, `start_task`, `complete_task`, `cancel_task`, `create_subtask`, `link_task_reminder` — Task Engine. Conservative create; ambiguous matches do not mutate. [TASKS.md](TASKS.md).
- `create_watcher`, `list_watchers`, `get_watcher`, `update_watcher`, `pause_watcher`, `resume_watcher`, `cancel_watcher`, `list_watcher_occurrences`, `run_watcher_now` — Watchers. Explicit future conditions and Gmail event alerts. Periodic composite reports are **not** this tool. External writes become proposed actions. [WATCHERS_AND_AUTOMATIONS.md](WATCHERS_AND_AUTOMATIONS.md).
- `create_scheduled_report`, `list_scheduled_reports`, `get_scheduled_report`, `update_scheduled_report`, `pause_scheduled_report`, `resume_scheduled_report`, `cancel_scheduled_report` — Scheduled Reports. Clock-time multi-source collection + synthesis + delivery. Success claims require `success=true` and `report_id`. [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md).
- `get_assistant_profile` / `update_assistant_profile` / `complete_assistant_onboarding` — current user’s assistant profile only. Never `user_id` from the model. [ASSISTANT_PERSONALIZATION.md](ASSISTANT_PERSONALIZATION.md).
- `search_conversation_history` — targeted raw-on-demand по **текущему** user.
- `get_project_context` — owner-only (`projects` capability). Derived project context including bounded ACTIVE group knowledge for attached groups, не raw dump. Не подмешивается в обычный prompt.
- `get_project_status` — owner-only (`projects`). Cross-source current state (blockers, waiting, open work). Does not replace `get_project_context`.
- `get_synthesis`, `get_person_status`, `list_waiting_for`, `list_commitments` — read-only synthesis over the user’s own indexed domains. [CROSS_SOURCE_SYNTHESIS.md](CROSS_SOURCE_SYNTHESIS.md).
- `search_group_knowledge` — owner-only (`group_analysis`). Explicit group search only.
- Google Calendar tools — owner-only (`google_calendar`). Live Google is the source of truth. [INTEGRATIONS.md](INTEGRATIONS.md).
- Gmail tools — owner-only (`gmail`). Live Gmail is the source of truth; no local mailbox. Search/list/read/thread/labels/draft/send/modify. Send always requires persisted confirmation. [INTEGRATIONS.md](INTEGRATIONS.md).
- GitHub tools — owner-only (`github`). Live GitHub is the source of truth; no local repo mirror and no shell git. Read repos/commits/files/search/issues/PRs/CI; controlled write: issue/comment/branch/PR create. No merge/delete/force/file-write. [INTEGRATIONS.md](INTEGRATIONS.md).
- `confirm_tool_action` / `cancel_tool_action` — only while a pending confirmation exists; require a server-side yes/cancel signal.

Gemini — production provider с function calling (`functionDeclarations` / `functionCall` / `functionResponse`). OpenAI и Anthropic chat работают; tool-enabled request им **не** отправляется молча (`supportsTools=false`).

Current user local datetime и IANA timezone инжектятся в system context на каждом turn. Calendar naive times use the same owner timezone.

Confirmation: read-only без confirm. Core reminder writes (`create_reminder`, `update_reminder`, `snooze_reminder`, `complete_reminder`, `cancel_reminder`), Core task writes (`create_task` / `update_task` / `start_task` / `complete_task` / `cancel_task` / `create_subtask` / `link_task_reminder`), Core watcher writes (`create_watcher` / `update_watcher` / `pause_watcher` / `resume_watcher` / `cancel_watcher`), Core scheduled-report writes (`create_scheduled_report` / `update_scheduled_report` / `pause_scheduled_report` / `resume_scheduled_report` / `cancel_scheduled_report`) and assistant profile writes remain allowed (provider null). External write + explicit user command = allowed except tools with `alwaysConfirm` (`send_gmail_message`). Watcher reactions never send Gmail, write Calendar, or write GitHub; they notify or propose. Gmail event watchers and scheduled mail reports are read-only (no mark-as-read, archive, label, or reply). Model-proposed = confirmation_required. Destructive = always confirmation_required and is persisted in `tool_confirmations`. Conservative yes/cancel parser plus Web/Telegram buttons. Модель не может self-authorize. Message history hydrates live `pending_confirmation.status` from `tool_confirmations`; only `pending` is actionable in Text and Voice. [INTEGRATIONS.md](INTEGRATIONS.md).

Reminders: Reminder Tool → Reminder Engine, не Calendar. [REMINDERS.md](REMINDERS.md).
