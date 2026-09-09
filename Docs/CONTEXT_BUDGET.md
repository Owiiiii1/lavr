> **CURRENT prompt budget.** Unrelated to LAVR documentation Phase 2.

# Context Budget Manager

**Status.** IMPLEMENTED / NOT VALIDATED (M22.3, 2026-09-04). Automated tests and live AI calls are deferred by Owner.

One LLM request has a bounded prompt, independent of how large the database becomes. A million messages, a hundred thousand Storage files, Gmail, GitHub, Telegram groups, and web research must not dump themselves into a single request.

See [WEB_RESEARCH.md](WEB_RESEARCH.md), [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md), [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md), [STORAGE.md](STORAGE.md).

---

## Invariant

After the conversation-summary threshold, adding more raw messages to MySQL must not materially grow the normal per-turn prompt.

1k messages vs 1M messages: the base request stays roughly the same bounded size. Retrieval complexity and indexes may grow. Raw history is never sent whole. Compaction never deletes raw messages.

---

## Components

| Piece | Role |
| --- | --- |
| `config/context_budget.php` | Named token slices, tool-round cap, no-progress threshold, provider retries |
| `config/ai_model_context.php` | Per-provider / per-model context windows + output reserve |
| `AiModelContextPolicy` | Resolves max context and input budget; unknown model → conservative default (32k / 2k) |
| `TokenEstimator` | Provider-neutral overestimate (Unicode chars/words + overhead). Prefer overestimating. |
| `ContextBudgetManager` | Assembles and trims one request until estimated input ≤ input budget |
| `ToolResultBudgetManager` | Second safety layer on every ToolResult (web / Gmail / GitHub / Storage / group) |
| `TurnBudgetTracker` | Per-turn web call caps + tool-result token counters |
| `ContextDiagnosticsLogger` | Safe metrics only (no prompt text) |

`ConversationContextBuilder` gathers slices. It does not append unlimited strings on its own.

Hard guarantee: before each provider call, `estimated_input_tokens <= input_budget`. The builder/manager trim until that is true. Do not rely on provider HTTP 400 “context too long”.

Voice transcripts are ordinary `messages`. A long voice session uses the same manager; it cannot build an unbounded prompt. There is no separate voice context window.

Input budget = model max context − reserved output − safety margin.

---

## Priority (highest first)

1. Platform / system instructions (never dropped)
2. Current user turn (never dropped; storage excerpt on that turn may shrink)
3. Tool / confirmation-critical application events
4. Conversational policy (clipped; dropped only after identity if the request still overflows)
5. User General Prompt
6. Recent current conversation (token-bounded, newest backwards, complete message boundaries)
7. Working context (topic / entities / trusted recent tool refs; conversation-scoped)
8. Current conversation summary
9. Relevant personal memories
10. Cross-chat summaries of the same user
11. Projects / attachments (projects are still tool-retrieved, not auto-injected)
12. Knowledge Layer slice (`knowledge_context`; dropped before memories on overflow)
13. Optional synthesis slice (`synthesis_context`; tiny; dropped **first** on overflow so it never crowds out Memory or the current turn)
14. Optional tool context already in the loop

Never truncate away system or the current user turn just to keep old memories.

---

## Source rules

| Source | How it enters one request |
| --- | --- |
| Recent current chat | Raw window, token-bounded, newest first. Message-count cap is only a query bound. |
| Working context | Derived compact block: topic mode, current/previous topic, recent entities, trusted recent tool ids/titles. Token slice `working_context`. Not raw history. |
| Conversational policy | Bounded C.1 rules (clarification, pronouns, initiative, STT tolerance). Slice `conversational_policy`. |
| Personality / identity | Single `PersonalityPresentationBuilder` over the assistant profile (+ optional Voice spoken hint). Slice `assistant_identity`. |
| Older current chat | `conversation_summaries` (incremental, coverage `from_message_id` / `to_message_id`) |
| Other chats | Summaries first. Raw only via `search_conversation_history`. |
| Personal memory | Retriever candidates, then budget cap |
| Knowledge Layer | Compact entity slice when C.1/working context names an entity or project. Never a full graph. Slice `knowledge_context`. Dropped before memories if the request overflows. [KNOWLEDGE_LAYER.md](KNOWLEDGE_LAYER.md). |
| Cross-source synthesis | Optional compact block when C.1 has an active project. Slice `synthesis_context` (~220 tokens). Tools-first; not injected every turn. Dropped first on overflow. [CROSS_SOURCE_SYNTHESIS.md](CROSS_SOURCE_SYNTHESIS.md). |
| Screenshots | Bounded derived summary text. Never historical image bytes (M22.2). |
| Persistent Storage | Never auto-inject whole files. Current attached file: bounded metadata + small excerpt. Historical: tools. Tool results still pass the global tool budget. |
| Web / Gmail / GitHub / groups | Tool results only, for that turn. Not standing context. |

---

## ToolResult budget

Local per-tool bounds remain. `ToolResultBudgetManager` is a second layer.

Shared token budget for all tool responses in one turn. Trim **content/excerpts/lists** first. Preserve success/error, ids, pagination/`truncated`, key metadata. Never emit invalid object shape.

If the remaining budget is too small: compact `tool_context_budget_exceeded` rather than a huge payload.

Forced final synthesis compacting: duplicate tool results (same semantic fingerprint: file + chunk indexes + query, not raw text) are replaced with a `duplicate` marker so the no-tools pass is not flooded with the same Storage chunks.

Hard `max_tool_rounds` (default 8) is an emergency cap. `no_progress_tool_rounds` (default 2) stops the tool phase earlier when calls add no new information. `provider_retries` / `final_synthesis_retries` apply to the model call only; tools are not re-executed.

---

## Conversation compaction

Existing `UpdateConversationSummaryJob` / `ConversationSummaryService`.

Refresh when unsummarized semantic messages exceed `memory.summary_message_threshold` **or** estimated tokens exceed `context_budget.summary_refresh_tokens`.

Incremental: previous summary + messages after `to_message_id`. Unsummarized load is capped (`unsummarized_message_cap`). Summary text itself is capped (`summary_max_chars`); oversized previous summaries are recompressed.

Raw messages are never deleted. Summary is derived. Coverage boundary already exists on `conversation_summaries`.

---

## Diagnostics

Log channel `context budget` per AI request:

- user id, conversation id
- model / configuration role
- estimated_input_tokens, output_reserve, input_budget
- counts/tokens per source
- trimmed counts
- utilization percent
- working_context
- knowledge_context
- synthesis_context
- overflow_prevented
- continuity_source, topic_mode, reference_outcome, clarification_reason, working_context_tokens (C.1; no prompt text)

No actual texts. Compact copy on assistant `metadata.ai.context`. Admin AI settings remain credential/config UI; no new admin subsystem.

---

## Scale notes

Context queries used for prompt assembly use LIMIT / exists checks. They must not hydrate all messages of a conversation.

Persistent files stay on local private disk. Future object storage is a later threshold, not this milestone.

Web search snippets and fetched pages consume web/tool budget for the current loop only. They are not persisted as context or memory.
