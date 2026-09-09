# Jarvis — current implementation snapshot

**Date:** 2026-09-09 (Scheduled Reports — morning body + calendar DI fix)
**Host path:** `/var/www/jarvis`  
**Public URL:** https://jarvis.owlsolutions.net  
**GitHub:** https://github.com/Owiiiii1/JARVIS.git

This file is a **runtime snapshot**. If it disagrees with older milestone prose, this file and the code win.

### Status vocabulary

| Status | Meaning |
| --- | --- |
| IMPLEMENTED | In production code |
| MANUAL PASS | Owner confirmed in production |
| MANUAL PARTIAL | Owner confirmed part of the flow |
| IMPLEMENTED / NOT VALIDATED | Code exists; not Owner-confirmed |
| LIVE BUG | Code exists; Owner reports it does not work as expected |
| DEFERRED | Explicitly not current work |
| CANCELLED | Will not be built |

---

## Manual production validation

**PASS — Core Daily Workflow (2026-09-07):**

Owner completed the sequential campaign in [VALIDATION_CORE_WORKFLOW.md](VALIDATION_CORE_WORKFLOW.md) by hand. Clean repeat chat: **Validation Core 2**. Result: **MANUAL PASS 10/10** (Scenarios 1–10). Cursor did not execute the scenarios, did not create production records, and did not run automated tests for this close-out.

This is a pass of the **tested core chain**, not of every subsystem edge case. Confirmed behaviors:

1. Conversation continuity / reference resolution
2. Task + visible/manageable subtask
3. Reminder create/update
4. Internal watcher creation
5. Knowledge cross-chat retrieval
6. Cross-source synthesis
7. Waiting / explicit commitments
8. State-change propagation through Task → Reminder → Watcher → Synthesis
9. Overview refresh / dedupe / canonical-state precedence
10. Humanized Workspace presentation
11. Memory vs Knowledge separation
12. Conversation delete preserving durable Tasks / Knowledge / Memory semantics

The first Scenario 8 on the earlier `Validation Core` chat was a **LIVE FAIL** (stale derived Overview + backend wording on screen). Commit `bcca7ca8ea72181cb6414b2f9d3102178b8b6c63` fixed those defects. Scenario 8 on **Validation Core 2** is **MANUAL PASS**. Workspace Presentation is **MANUAL PASS** after that live revalidation.

Layer wording after this campaign:

| Layer | After Core Daily Workflow |
| --- | --- |
| C.1 Conversation Intelligence | **MANUAL PASS** for the continuation / reference / clarification behavior exercised in the campaign. Not a claim that all Conversation Intelligence is covered. |
| E.1 Knowledge | **MANUAL PASS** for the tested core flow (explicit fact → async extraction → retrieval → provenance). Not all Knowledge edge cases. |
| E.2 Watchers | **MANUAL PASS** for the internal task watcher flow. External Gmail / Calendar / GitHub watchers remain deferred. |
| E.3 Cross-source Synthesis | **MANUAL PASS** for the tested core synthesis / Overview / waiting / state-change flow. |
| B.2 Tasks / Reminder Center as used in the campaign | **MANUAL PASS** for that core productivity chain. Briefs, proactive suggestions, and Notification Center as a full product are not claimed. |
| Workspace Presentation | **MANUAL PASS** after Scenario 8 revalidation |

**PASS — core ordinary user (M25U.2):**

- Owner created an ordinary user via Admin
- login works
- `/chat` works
- normal test requests work

**PASS — Owner Workspace (earlier 2026-09-04):**

- image upload + Gemini vision
- persistent text-file upload / Storage retrieval through chat
- Gemini Google Search web research

**PASS — Voice pipeline (M23–M24.1.1); current PTT UI implemented:**

- Voice mode starts
- microphone permission/session starts
- hold-to-talk recording; release sends the turn
- Gemini STT
- Jarvis generates a reply
- ElevenLabs TTS plays audio
- each user can select a personal TTS voice

The former hands-free «Диалог» VAD capture was removed from Рация. **Диалог Beta** is a new parallel Web mode (ElevenLabs realtime), **IMPLEMENTED / NOT VALIDATED**, default off (`ELEVENLABS_REALTIME_ENABLED=false`). Рация remains the default and is not removed.

**PARTIAL — M25U.3:**

- Onboarding / «Знакомство» **appears** (Owner)
- Full onboarding conversation / completion / profile update: **not** MANUAL PASS
- Reminders panel / Reminders 2.0: **MANUAL PASS for confirmed live core flow** (Web Push, Reminder Center, basic user flow, and the Core Daily Workflow create/update). Not exhaustive DST/recurrence/multi-device MANUAL PASS.
- `create_reminder` without Telegram: covered by that same live core flow
- Phase B.2 briefs / proactive / Notification Center as a full product: **IMPLEMENTED / NOT VALIDATED**

**Not claimed:** A/B IDOR campaign; combined Google/GitHub live campaign; Tavily; `fetch_web_page` as a distinct Owner check; screenshot purge; destructive Storage delete; external watcher campaigns; ElevenLabs realtime Диалог Beta; Telegram Groups; DST/timezone edge cases; historical retry/prune; Mobile / Client API.

---

## 1. Git

| Item | Value |
| --- | --- |
| Branch | `main` |
| HEAD | `main`, aligned with `origin/main` after Core Daily Workflow documentation close-out |
| Origin | `https://github.com/Owiiiii1/JARVIS.git` |

Production checkout is the GitHub source of truth. Gemini STT request-shape and bounded ElevenLabs voice fallback are committed. Laravel Boost is require-dev tooling in a separate commit. `.env` stays gitignored.

---

## 2. Runtime / stack

| Component | Actual |
| --- | --- |
| OS | Ubuntu 24.04 LTS |
| PHP CLI / FPM | 8.5.8 (`php8.5-fpm.sock`) |
| Laravel | 13.30.1 |
| Composer | 2.7.x |
| Database | MySQL 8.0, database `jarvis` |
| Redis | **not used** (cache/session/queue = database) |
| Queue | `database` |
| APP_ENV | `production` |
| APP_DEBUG | `false` |

Composer (relevant): `owlsolutions/custom-admin-kit` v0.5.0, Inertia, Ziggy, Nutgram (transitive via kit).

AI / Telegram / ElevenLabs credentials: encrypted DB columns, not `.env`. Do not document secrets.

---

## 3. Deployment

| Item | Actual |
| --- | --- |
| Domain | `jarvis.owlsolutions.net` |
| nginx | `/var/www/jarvis/public`, HTTP→HTTPS |
| TLS | Let's Encrypt |
| Scheduler | crontab `schedule:run`; `jarvis:reminders:dispatch` every minute; `jarvis:tasks:dispatch` / `jarvis:proactive:dispatch` every 5 minutes; `jarvis:briefs:dispatch` every minute; attachment purge hourly; `jarvis:voice:cleanup-temp` every 5 minutes; `jarvis:reliability:recover-stale` every 15 minutes; fallback `queue:work` for `analysis,memory,default` (`--timeout=180`). Long-running worker: `jarvis-queue.service` same queues. |
| Telegram queue | deploy-user crontab `flock` worker (host-specific) |

Vite production build is generated on deploy (`public/build` gitignored).

---

## 4. Database

Engine: MySQL. CRM tables were dropped (M0). App migrations listed as Ran.

### Tables (product)

Includes identity/conversation/memory/integration/voice tables plus `reminders`, `reminder_deliveries`, `reminder_occurrences`, `push_subscriptions`, `tasks`, `jarvis_notifications`, `user_productivity_settings`, E.1 knowledge tables, and E.2 `watchers`, `watcher_occurrences`.

See [DATABASE.md](DATABASE.md).

---

## 5. Product surfaces

| Surface | Path | Status |
| --- | --- | --- |
| Login | `/` | IMPLEMENTED |
| Owner Workspace | `/jarvis` | PRIMARY, MANUAL PASS (selected flows + Core Daily Workflow) |
| User Workspace | `/chat` | MANUAL PASS (core) |
| `/cabinet` | compatibility redirects + leftover JSON | LEGACY |
| Admin | `/dashboard`, `/settings/*` | IMPLEMENTED |
| Voice | workspace Text/Voice + `/…/voice/sessions/*` | MANUAL PASS |
| Storage page | `/jarvis/storage` | Owner-only, IMPLEMENTED |
| Projects | `/projects` | Owner, IMPLEMENTED |
| Telegram Groups | `/telegram-groups` | Owner, IMPLEMENTED / NOT VALIDATED |
| Desktop | — | CANCELLED |
| Mobile | — | DEFERRED |
| Versioned Client API | — | DEFERRED |

Frontend: `resources/js/personal-workspace/PersonalWorkspace.jsx` shared, with Settings split into `resources/js/personal-workspace/settings/*`. Capabilities are presentation flags; backend ownership is authoritative.

Main Workspace is chat + Task / Reminder / Watcher / **Report** / Notification centers + compact **Обзор** (Сегодня и ближайшее / Нужно внимание / Жду / Что изменилось / Открытая работа) + Voice + compact **Настройки**. Memory and Integrations are **not** on the main screen; they live in Settings.

Workspace conversation delete is implemented for Owner and ordinary users. Sidebar overflow menu → confirmation dialog → `DELETE /jarvis/chats/{conversation}` or `DELETE /chat/chats/{conversation}`. Own personal conversations only (`ensureOwned`; Owner is not a bypass for someone else’s chat). Group conversations are 404. Hard delete of the chat and child messages/ephemeral screenshots; tasks, reminders, projects, persistent Storage files, durable memories, and Knowledge entities survive with sources detached. Deleting the open chat switches to the latest remaining personal chat, or creates `Основной` if none remain. No full page reload. **MANUAL PASS** (original Workspace delete + Core Daily Workflow Scenario 10 regression).

Phase C.1 Conversation Intelligence is **MANUAL PASS for the tested continuation / reference / clarification behavior**. Same Conversation Engine. Derived working context (topic mode, recent entities, trusted recent tool refs, temporary style) plus clarification/initiative policy. Mutation tools do not guess ids. Web composer can send a new message while a previous turn is thinking; stale JSON is ignored. Server generation is not cancelled. Full Conversation Intelligence coverage is **not** claimed.

Agent runtime recovery (2026-09-08): **IMPLEMENTED / READY FOR OWNER VALIDATION**. Every user turn must end in a useful answer, a partial answer with a limitation, or a short unavailable message after recovery is exhausted. Tool loops are turn-scoped (a new message such as «эй» does not resume an unfinished Storage plan). Repeated no-progress tool calls force a no-tools synthesis before the hard round cap. Read-only tool success never falls back to «Готово.» Cursor did **not** run PHPUnit, live provider calls, or live Storage/Gmail/Calendar/GitHub tools. Owner should re-check the CNC Storage analysis that previously ended with «техническая ошибка».

Phase C.2 Beta (ElevenLabs realtime Web voice) is **IMPLEMENTED / NOT VALIDATED**. Parallel to Рация. Telegram Voice unchanged. Legacy removal NOT NOW.

Phase E.1 Knowledge Layer is **MANUAL PASS for the tested core flow**. Relational entities/relations/events with provenance. Settings → Knowledge. Bounded conversation slice. Not all Knowledge edge cases. [KNOWLEDGE_LAYER.md](KNOWLEDGE_LAYER.md).

Phase E.2 Watchers is **MANUAL PASS for the internal task watcher flow**. Explicit persisted conditions; Notification Center / Web Push delivery; no silent external writes. Workspace Center **Автоматизации**. A one-shot task watcher whose condition can only match while the task is open is finished with `cursor.resolved_reason = task_closed` when that task is completed or cancelled, instead of staying Active forever; `status_changed` watchers still fire on the closing transition. Recurring Gmail morning **digest watchers are retired as the path for periodic reports**. “Каждое утро дай сводку почты / планы на завтра” now creates a **Scheduled Report**, not a Gmail or Calendar watcher. Gmail **event** monitoring (“жди письмо от школы / следи за письмами от @example.com”) remains `create_watcher`. Status: **IMPLEMENTED / READY FOR OWNER VALIDATION** — Cursor did not call Gmail, did not evaluate Owner watchers, and did not change Owner watchers #191 / #193 / #194. [WATCHERS_AND_AUTOMATIONS.md](WATCHERS_AND_AUTOMATIONS.md). [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md).

Phase E.3 Cross-source Synthesis is **MANUAL PASS for the tested core synthesis / Overview / waiting / state-change flow**. Derived FactPack over Knowledge / Tasks / Reminders / Watchers / Projects / conversation summaries. Tools-first; tiny `synthesis_context` only with an active project. No integration polling. No `waiting_items` table. Phase E as a whole is **not** complete. [CROSS_SOURCE_SYNTHESIS.md](CROSS_SOURCE_SYNTHESIS.md).

Authoritative-domain precedence applies to the derived slices, not only to the narrative: upcoming, attention, waiting-for, open-work and blockers all resolve knowledge entities and watchers back to the canonical task before deciding anything is live, and a task change carries one fingerprint across the task table and its knowledge event so a completion is one card. Item titles and reasons are human sentences produced by the presentation layer. **MANUAL PASS** after Scenario 8 revalidation on Validation Core 2. [WORKSPACE_PRESENTATION.md](WORKSPACE_PRESENTATION.md).

Workspace Settings sections: Profile, Assistant, Memory, Knowledge, Productivity, Voice, Integrations. Desktop: nav + detail. Mobile: list → detail. Direct section: `?settings=memory` / `?settings=knowledge` / `?settings=integrations` on first load (allowlist only). Opening Settings from the UI does not rewrite `history.state`, so the chat list stays intact.

After a successful foreground chat turn, badges and open panels refresh via `GET /jarvis/workspace/status` and `GET /chat/workspace/status` plus turn-payload counts. A mutation made directly in the Tasks / Reminders / Watchers panel also refreshes an open **Обзор**. No page reload, no polling, no WebSocket. Scheduler events still appear on next open / Push / navigation.

Regular user capabilities: chat, memory, knowledge, watchers, **scheduled_reports**, telegram_dm, reminders, tasks, notifications, cabinet, personal_workspace, profile, web_research, voice, storage. **Not** projects, admin, Google, GitHub. User Settings → Integrations shows Telegram pairing only. External (Gmail/Calendar/GitHub) watchers remain Owner-only.

---

## 6. Voice

Committed path: two Web modes. **Рация** (default): push-to-talk, Gemini STT, ElevenLabs HTTP TTS, responsive Orb — Owner MANUAL PASS for the core pipeline. **Диалог Beta**: ElevenLabs realtime transport + Jarvis Custom LLM adapter — IMPLEMENTED / NOT VALIDATED; disabled unless env is configured. Admin Voice panel shows Realtime Conversation Configured / Not configured. Each user chooses one of six curated voices in Workspace settings (`users.voice_id`); Beta passes it as an Agent TTS override when the catalog matches. Empty Gemini `audioTranscriptionConfig` is sent as JSON `{}`. If a selected ElevenLabs voice is unavailable on the account, TTS makes at most one fallback request to the instance/default voice; auth, quota, rate-limit, and generic server errors do not retry. Live Gemini/ElevenLabs validation was not run. [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md).

Telegram Voice Replies (`sendVoice`): **MANUAL PASS** for delivery; Telegram TTS speed is **IMPLEMENTED / READY FOR OWNER VALIDATION**. Admin Voice/Speech setting `telegram_tts_speed` (default **1.15**, range **0.70…1.20**) applies only to Telegram ElevenLabs HTTP TTS. Web Рация and Диалог Beta are unchanged.
Telegram Voice Input (DM `Message.voice` → existing Gemini STT → Core): **IMPLEMENTED / NOT VALIDATED**. Groups still store `[voice]` placeholder (no STT). Default Telegram reply mode remains **text**. C.2 does **not** instantiate a realtime ElevenLabs agent on Telegram. [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md).

---

## 7. Personalization (M25U.3)

Table `user_assistant_profiles`. Tools: `get_assistant_profile`, `update_assistant_profile`, `complete_assistant_onboarding`. Owner seeded Jarvis / completed. User onboarding UI exists; Owner confirmed **entry**. Completion E2E not confirmed.

Personal voice preference: nullable `users.voice_id`; effective fallback is the configured instance/default voice. The same selected voice is passed explicitly to Web Voice and Telegram TTS.

---

## 8. Reminders

Phase B.1 Reminders 2.0: Owner **MANUAL PASS for confirmed live core flow** (Web Push, Reminder Center, basic user flow, Core Daily Workflow create/update). Not exhaustive edge-case MANUAL PASS. Telegram remains an optional adapter.

## 8.1 Tasks & productivity

Phase B.2 core Task Center flow as exercised in Core Daily Workflow: **MANUAL PASS**. Briefs, proactive suggestions, and Notification Center as a full product remain **IMPLEMENTED / NOT VALIDATED**. Separate `tasks` domain, Task Center, Notification Center, opt-in Daily/Evening/Weekly briefs, bounded proactive suggestions. [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md).

## 8.2 Scheduled Reports

**IMPLEMENTED / READY FOR OWNER VALIDATION** (routing + model). **LIVE BUG fixed 2026-09-09** (truncated AI body + calendar DI). **Mail digest** is a spoken summary, not a sender-subject list. Next 08:30 / 22:00 / 09:00 slots still need Owner confirmation.

Named, persisted, multi-source reports at a known local time. Not a Reminder and not a Watcher. Chat tools `create_scheduled_report` / list / get / update / pause / resume / cancel. Workspace Center **Отчеты**. Scheduler `jarvis:reports:dispatch` every 5 minutes, idempotent per local date+time slot.

Canonical types: `daily_plan`, `tomorrow_plan`, `mail_groups_digest`, `custom_composite`. Period is independent of fire time (`today` / `tomorrow` / `since_previous_report` with first-run last 24h).

Owner live failure (conversation Основной, 2026-09-08): Jarvis claimed three daily reports. Production objects were watchers #193 (calendar change, not 22:00 tomorrow plan), #194 (Gmail digest at 08:30), #191 (Gmail digest; no Telegram groups). Cursor did **not** mutate those rows. After deploy Owner should cancel them in Автоматизации and recreate via chat.

**Owner live (2026-09-09 08:30 Europe/Rome):** report **#2** ran `partial`. Collector had the two WOW Cleaning tasks, but the delivered Telegram/notification body was the truncated AI greeting `Доброе утро. Сводка на сегодня,`. `source_errors` said calendar unavailable. Google account **#479** was connected with calendar scope; Laravel 13 skipped injecting `GoogleCalendarService` / `GoogleGmailService` / `IntegrationAccountService` because the collector constructor defaults them to `null` and those concretes were not bound. Fix: explicit `ScheduledReportCollector` binding; reject truncated/incomplete AI phrasing and keep the deterministic report. Today’s 08:30 slot is consumed (unique `slot_key`); Cursor did not re-send it.

**Owner live (2026-09-09 09:00 Europe/Rome):** report **#3** (`mail_groups_digest`) succeeded and called Gmail (4 messages), but the body was a sender-subject list because the composer’s fallback is a list and AI phrasing did not land (`ai_phrased=false`). That is the intended gap of the first digest renderer, not a routing miss. **Fix:** digest text is a grounded prose summary (important vs other vs noise count; Gmail snippet at compose time only, never stored). AI prompt for `mail_groups_digest` writes a spoken digest instead of rewriting the list. Truncated AI still falls back to the prose summary. Today’s 09:00 slot is consumed; Cursor did not re-send it.

**Root cause of the missing phrasing (2026-09-09, Owner test send):** the Owner role runs a reasoning model (`gemini-3.7-flash`), and the phrasing call capped `max_tokens` at 400–500. Hidden thinking tokens ate the whole budget — the live call returned 20 visible tokens with `finishReason=MAX_TOKENS`, so the completeness guard discarded it and every brief silently fell back to deterministic text (`scheduled_report.phrasing_skipped reason=empty`). Budget now comes from `productivity.briefs.phrasing_max_tokens` (1600) and rejections log `productivity.phrasing_rejected` with the provider finish reason. Owner test send after the fix returned `ai_used=true` and a spoken Russian digest. The deterministic fallback no longer quotes email snippets (they arrive in the sender’s language) and invisible preview padding is stripped before phrasing.

Generic B.2 Productivity Briefs stay opt-in (default off). If an active Scheduled Report already covers daily_plan or tomorrow_plan, the matching brief mode is skipped so the two engines do not double-send.

Cursor did not dispatch a live report, did not call Gmail/Calendar/Telegram APIs, and did not create or mutate Owner reports.

Panel presentation: one card per task with its schedule as the secondary line, priority only when high or urgent, an expandable subtask list with «X из Y подзадач выполнено», and a Workspace dialog («Выполнить всё» / «Вернуться») when a parent still has open subtasks. A subtask whose parent is already closed is listed in the active sections as «Подзадача задачи «…»» so nothing open is invisible. **MANUAL PASS** with Workspace Presentation after Scenario 8 revalidation. [WORKSPACE_PRESENTATION.md](WORKSPACE_PRESENTATION.md).

---

## 9. Integrations

Code: Google OAuth (Gmail + Calendar tools; **no Drive**), GitHub OAuth + tools, Telegram bot, ElevenLabs TTS, Web Research (`gemini_google` / `tavily` / disabled). Owner-only except Voice/research/storage capabilities for users as listed above. Live Google/GitHub campaign: NOT VALIDATED.

Google OAuth **client** configuration (Client ID / Client Secret / Redirect URI) can be managed in Admin → Settings → Integrations → Google. Stored in `google_oauth_settings`; Client Secret encrypted at rest. DB overrides `.env`; `.env` remains fallback. OAuth **account** tokens stay separately encrypted in `integration_accounts`. Admin save is **READY FOR OWNER VALIDATION**, not MANUAL PASS. Cursor did not Connect Google or call Gmail/Calendar.

**Gmail read/send (Owner live):** read/send flow was Owner-tested. After a successful confirmed send, Voice mode still showed the same confirmation card (stale `pending_confirmation` on the historical message; overlay selected any id). That is a **LIVE BUG** in confirmation presentation, not in Gmail send itself. Cross-mode confirmation lifecycle fix is **READY FOR OWNER REVALIDATION**. Do **not** mark Gmail confirmation **MANUAL PASS** until Owner rechecks Text → Voice after confirm/cancel/expiry.

---

## 10. What is not here

- Desktop / Tauri / tray / hotkey
- Mobile app
- Public registration
- Knowledge Graph product / Neo4j (E.1 is a relational index; E.2 external watchers are not a generic agent)
- Wake word
- Real-time WebSocket/SSE for scheduler events
- Telegram Voice Input live Owner checklist (code shipped)
- Phase C.2 Beta live Owner A/B (code shipped; not MANUAL PASS; do not remove Рация)
- Historical async retry/prune (classified; Owner decides)
- External watcher campaigns (Gmail / Calendar / GitHub) and proposed-action → confirmation → external write
- DST / timezone edge cases
- Destructive Storage edge cases
- Full IDOR / security campaign
- Optional future integrations

Live campaigns still open: [DEFERRED_VALIDATION.md](DEFERRED_VALIDATION.md). Core Reliability is IMPLEMENTED; historical failures CLASSIFIED.
