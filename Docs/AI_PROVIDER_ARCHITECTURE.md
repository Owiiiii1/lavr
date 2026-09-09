> **CURRENT AI provider mapping.** LAVR is single-client; “Default User Conversation AI” is origin naming.

# AI Provider Architecture

Jarvis не зависит от одного HTTP API и одной модели. Business logic не импортирует vendor SDK.

**Нет** одной Conversation AI на owner и users. Это разные configuration domains. ADR-013 уточняется ADR-034.

Фактический код (M4): runtime source of truth = `ai_role_settings`. `ai_provider_settings` хранит credentials / listModels. Поле `is_active` не определяет conversation model.

---

## Три обязательных logical configurations

| Config | Кто использует | Назначение |
| --- | --- | --- |
| **Owner Conversation AI** | только Owner Space | общение owner, tool calls (Calendar/Gmail/GitHub/Storage/web research/group search/reminders) |
| **Owner Analysis AI** | jobs (any user_id scope + group analysis) | personal memory extract, conversation summaries; M14 Telegram group knowledge (`telegram_group_knowledge`) |
| **Default User Conversation AI** | все User Spaces | общение обычных users; **не** наследует Owner Conversation AI |

Каждая:

- provider;
- model;
- system / platform prompt;
- parameters;
- для Owner Conversation — tools availability.

Owner может держать дорогую модель; users — отдельную дешёвую. Никакой User **не** резолвит Owner Conversation config «по умолчанию».

Optional **later**: per-user model override поверх Default User Conversation AI. Не обязателен для MVP.

Analysis AI **не** обслуживает обычный user DM. Background Memory Engine uses Owner Analysis AI as the analysis engine; derived rows stay on the source `user_id`. User A output never becomes User B context. M14 Group Analysis also uses **only** Owner Analysis AI and writes `telegram_group_knowledge` (never personal `memories`).

Слоты later (classification, embeddings, …) выделяются из Owner Analysis без смены engines.

---

## Resolve

```
resolveConversationAI(user):
  if user.role === owner → Owner Conversation AI
  else → Default User Conversation AI
       → optional future per-user override
```

```
resolveAnalysisAI():
  → Owner Analysis AI
  (background memory extract/summaries for any user — result scope is always source user_id;
   M14 group analysis — result scope is telegram_group_id, never personal memory)
```

Conversation Engine не знает vendor. Не `if user_id === 1`.

---

## Prompt hierarchy (personal turn)

1. Platform prompt **выбранного** conversation config (owner vs default user);
2. Current local time / timezone;
3. Tool context;
4. **User General Prompt** этого space;
5. Relevant personal memories (labelled block);
6. Compact user profile if present;
7. Relevant summaries of **other** chats of this user (not their raw);
8. Current conversation summary if the chat is longer than the recent window;
9. Current conversation recent/raw + current inbound.

User General Prompt редактирует **сам user** в Workspace settings (owner — в своих settings). Не отменяет platform/security.

---

## Tools

Owner Conversation AI: multi-step tool loop в одном turn. [INTEGRATIONS.md](INTEGRATIONS.md). Tools: `create_reminder`, `list_reminders`, `update_reminder`, `snooze_reminder`, `complete_reminder`, `cancel_reminder`, `search_conversation_history`, `get_project_context` (owner-only; may include bounded group-derived knowledge for attached groups), `search_group_knowledge` (owner-only, capability `group_analysis`; derived-first group search, no silent prompt injection), Google Calendar tools (`list_google_calendars`, `list_calendar_events`, `get_calendar_event`, `search_calendar_events`, `google_calendar_freebusy`, `create_calendar_event`, `update_calendar_event`, `delete_calendar_event`; capability `google_calendar`), Gmail tools (`search_gmail`, `list_gmail_messages`, `get_gmail_message`, `get_gmail_thread`, `list_gmail_labels`, `create_gmail_draft`, `send_gmail_message`, `modify_gmail_labels`; capability `gmail`), GitHub tools (`list_github_repositories`, `get_github_repository`, `list_github_branches`, `list_github_commits`, `get_github_commit`, `compare_github_refs`, `get_github_file`, `search_github_code`, `list_github_issues`, `get_github_issue`, `list_github_pull_requests`, `get_github_pull_request`, `get_github_pull_request_diff`, `list_github_workflow_runs`, `get_github_workflow_run`, `create_github_issue`, `comment_github_issue`, `create_github_branch`, `create_github_pull_request`; capability `github`), Storage tools (`list_storage_files`, `search_storage_files`, `get_storage_file`, `search_storage_file_contents`, `read_storage_file_chunks`, `delete_storage_file`; capability `storage`), Web Research (`search_web`, `fetch_web_page`; capability `web_research`; [WEB_RESEARCH.md](WEB_RESEARCH.md)), plus `confirm_tool_action` / `cancel_tool_action` when a pending confirmation exists. Voice tools are not registered. Disconnected Google/GitHub still expose definitions; execution returns a safe connect/scope error. `search_web` uses `WebSearchManager` only (`gemini_google` / `tavily` / `null`); unconfigured search returns `web_search_not_configured`. Gmail→Calendar in one turn is supported (search/read mail, then create event). GitHub/Storage/web content is never auto-injected into the prompt. Every ToolResult also passes `ToolResultBudgetManager`. [CONTEXT_BUDGET.md](CONTEXT_BUDGET.md).

User Conversation AI: reminder, history search, assistant profile/onboarding, Telegram response mode, instance Web Research, Storage read/search/chat files. Не Gmail/Calendar/GitHub/groups/projects. `delete_storage_file` and Storage page are owner-only.

Every tool execute goes through `ToolExecutionService`. Conversation Engine does not import provider SDKs. `search_web` does not call Tavily, Gemini Search, or HTTP itself.

Порт chat/complete возвращает text **и** tool requests. `one message ≠ max one tool call`. Tool rounds capped by `context_budget.max_tool_rounds` (default 8). Runtime also exits the tool phase on no-progress repetition (`no_progress_tool_rounds`, default 2) and then runs a no-tools final synthesis. Transient provider failures (timeout, 429, 5xx, empty response) retry without re-executing tools (`provider_retries`). Hard auth/config/safety/serialization errors are not retried. Identical mutation fingerprints in one turn are not executed twice.

Read vs write is explicit on `ToolMeta` (`ToolMutationKind`: read / write_internal / write_external / destructive). `AiFailureFallback` may say «Готово.» only for known mutations. Read/search/fetch/compare tools never complete as «Готово.» User-facing unavailable copy is short; provider exception names stay in logs (`agent_turn` trace: round count, tool names, repetition, forced synthesis, retries, outcome — no payloads, prompts, or secrets).

`AiChatRequest` передаёт provider-neutral `ToolDefinition`. `AiChatResponse` возвращает text, zero or more `ToolCall`, finish reason, usage.

Model context windows are resolved by `AiModelContextPolicy` (`config/ai_model_context.php`). Unknown models use a conservative default. `TokenEstimator` overestimates input tokens; `ContextBudgetManager` trims until estimated input fits the computed input budget.

**Gemini (production):** function calling обязателен — `tools.functionDeclarations`, `functionCall`, `functionResponse` обратно модели, затем natural-language answer. Conversation Engine не парсит Gemini JSON в ReminderService. `supportsVision=true`. Current-turn images become Gemini `inlineData` only inside `GeminiClient`. Owner 2026-09-04 **MANUAL PASS**: Workspace image upload and Gemini vision recognition. Attachment visual summaries (screenshot expiry path) remain IMPLEMENTED / NOT VALIDATED. They use the same gateway + Owner Conversation config (vision-capable); they do not hardcode Gemini HTTP and do not change the Owner Analysis AI role.

Gemini Google Search grounding is a `WebSearchProvider` (`gemini_google`), not Conversation Engine `chat()`. Owner 2026-09-04 **MANUAL PASS** for live search / current-information retrieval. `fetch_web_page` and Tavily remain NOT VALIDATED. See [WEB_RESEARCH.md](WEB_RESEARCH.md).

**OpenAI / Anthropic:** chat без tools. `supportsTools=false`. `supportsVision=false` in M22.1 — image turns return safe `vision_not_supported`. Do not silently drop images. Do not fake vision.

Provider capability flags live on `AiProviderClient` / `AiProviderManager` / `ProviderAiChatGateway`, not as model-name checks in Conversation Engine.

Internal content is provider-neutral: `AiChatMessage` + `AiContentPart` (`text` | `image`). Text-only paths stay string `content`.

---

## Контракт провайдера

- messages + system + params + optional tool definitions;
- text и tool calls;
- usage/latency;
- ошибки.

Speech: [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md). Voice uses **the same** conversation AI config of that space. STT/TTS provider selection does not change Owner or Default User Conversation AI.

Gemini STT (`GeminiSpeechToTextProvider`) reuses the existing Gemini `ai_provider_settings` credential through `GeminiCredentialResolver`. It calls Gemini API `generateContent` for transcription only. It is **not** Conversation AI, **not** `AiChatGateway`, and **not** a second Gemini key.

OpenAI Whisper STT, if enabled, uses the OpenAI credential only for `/audio/transcriptions`, never `ConversationAiService` / `chat()`. OpenAI is optional and not required for the current product.

---

## Admin UI

Owner Settings → AI:

1. **Provider Credentials** — API keys, check connection, discovered models. Не runtime.
2. Три блока конфигурации (Owner Conversation / Owner Analysis / Default User Conversation): enabled, provider, model, system prompt, parameters.

Нельзя enable configuration, если provider не connected, model пустой, или chat не реализован.

Runtime **не** читает `is_active`.

Gemini Google Search (Web Research) reuses the same Gemini `ai_provider_settings` credential. It is **not** a second AI role and **not** Conversation Engine `chat()`. See [WEB_RESEARCH.md](WEB_RESEARCH.md).

Voice STT/TTS are separate ports (`SpeechToTextProvider` / `TextToSpeechProvider`). They must not be folded into `AiChatGateway`.
