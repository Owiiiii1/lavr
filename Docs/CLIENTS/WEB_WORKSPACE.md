# Personal Web Workspace

**Status.** PRIMARY product UI for **LAVR** (single client). Canonical route: `/lavr`. Legacy `/jarvis` and `/chat` redirect here.

Core user workflow **MANUAL PASS** (JARVIS origin). Voice **MANUAL PASS**. M25U.3 onboarding entry **MANUAL PARTIAL**. Reminders 2.0 **MANUAL PASS for confirmed live core flow**. Tasks / Notification Center **IMPLEMENTED / NOT VALIDATED**. Workspace Settings structured; Memory and Integrations live in Settings.

This is **not** the Admin Panel. `/cabinet` is a compatibility redirect only.

LAVR is a dedicated instance created from `Owiiiii1/JARVIS`: Laravel + Inertia/React. Not SaaS.

---

## Product role

| Surface | Route | For |
| --- | --- | --- |
| Admin Panel | `/dashboard` | technical setup: AI providers, integrations, Telegram groups, diagnostics |
| Personal Workspace | `/lavr` | the client talking to LAVR |
| Legacy workspaces | `/jarvis`, `/chat` | redirect to `/lavr` |
| Cabinet (deprecated) | `/cabinet` | redirects to `/lavr` |

Owner landing after login is `/lavr`. Admin remains `/dashboard`. There is no ordinary-user catalog.

Frontend: `resources/js/personal-workspace/PersonalWorkspace.jsx`. Inertia pages `Jarvis/Workspace` and `Chat/Workspace` re-export it. UI `capabilities` props are presentation-only; backend ownership/capability checks are authoritative.

While Owner impersonates a user, `/chat` uses that user’s Auth identity. A persistent banner (“Viewing as … / Exit impersonation”) is required. Admin/settings/projects stay unavailable. Ordinary users change their own password and timezone in workspace settings. [USER_ADMINISTRATION.md](../USER_ADMINISTRATION.md).

---

---

## Manual production validation — 2026-09-04

Owner confirmed in production (automated tests not run):

**PASS:**

- Owner Workspace image upload
- Gemini vision recognition
- persistent text-file upload
- persistent Storage retrieval/read
- Gemini Google Search web research

Not Owner-checked here: screenshot expiry/purge, artifact copy as a separate UX check, Storage library rename/download/delete, `fetch_web_page`, Tavily, ContextBudgetManager, Google Calendar/Gmail combined smoke, GitHub runtime.

---

## Authorization

`/jarvis` requires an authenticated active **owner**. Middleware: `auth`, `user.active`, `owner.workspace`.

`/chat` requires an authenticated active **user**. Middleware: `auth`, `user.active`, `personal.workspace` (owner is redirected to `/jarvis`).

- Guest → login.
- `role=user` on `/jarvis` → redirect to `/chat`.
- `role=owner` on `/chat` → redirect to `/jarvis`.
- Owner identity is enough for `/jarvis`. Workspace does **not** require `integrations_admin` just to open.

No hardcoded owner id.

Owner `/cabinet` redirects to `/jarvis`. User `/cabinet` redirects to `/chat`.

---

## Same Core

Workspace uses the existing Owner Space and engines:

- `conversations` / `messages` (no `workspace_conversations`)
- `ConversationTurnService` / Conversation AI
- Memory Engine
- projects, reminders, group knowledge tools
- Google Calendar / Gmail tools
- GitHub tools (M21)
- Storage tools (M22.2)
- Web Research tools (M22.3: `search_web`, `fetch_web_page`). Provider and limits are Admin-only (M22.3.1). Workspace shows read-only web search status; no technical limit editor.
- `tool_confirmations`

This is **not** a second chat engine and **not** a second owner memory.

M25U.3: ordinary-user header shows the chosen assistant name (fallback **Assistant**). Owner header stays Jarvis. Onboarding is a normal **Знакомство** chat (optional, not a gate). Header actions: **Задачи**, **Напоминания**, **Уведомления**, Text/Voice, **Настройки**. Create remains conversational where needed. Same centers on `/jarvis` and `/chat`, scoped to the effective user. [ASSISTANT_PERSONALIZATION.md](../ASSISTANT_PERSONALIZATION.md).

Telegram-created personal chats appear in `/jarvis`. New Chat creates a normal personal conversation (`kind=personal`). Default unused visit uses `ConversationService::latestOrDefault()` (existing recent chat, otherwise `Основной`).

Web inbound stays `channel=web` + client UUID idempotency. Channel is transport, not UI branding. There is no `workspace` channel enum.

---

## Routes

| Method | Path | Name |
| --- | --- | --- |
| GET | `/jarvis` | `jarvis.index` → redirect to last/recent personal chat |
| GET | `/jarvis/chats/{conversation}` | `jarvis.chats.show` |
| POST | `/jarvis/chats` | `jarvis.chats.store` |
| PATCH | `/jarvis/chats/{conversation}` | `jarvis.chats.update` |
| DELETE | `/jarvis/chats/{conversation}` | `jarvis.chats.destroy` (JSON; `/chat` mirror; own personal chat only) |
| POST | `/jarvis/chats/{conversation}/messages` | `jarvis.messages.store` (JSON or multipart `body` + `images[]` + `files[]`) |
| GET | `/jarvis/chats/{conversation}/messages/older` | `jarvis.messages.older` |
| GET | `/jarvis/chats/{conversation}/attachments/{attachment}/preview` | `jarvis.attachments.preview` (auth + ownership; 404 after purge) |
| GET | `/jarvis/chats/{conversation}/attachments/{attachment}` | `jarvis.attachments.show` (auth + ownership; 404 after purge) |
| GET | `/jarvis/storage` | `jarvis.storage.index` owner persistent files |
| POST | `/jarvis/storage` | `jarvis.storage.store` |
| GET | `/jarvis/storage/{file}` | `jarvis.storage.show` (`public_id`) |
| PATCH | `/jarvis/storage/{file}` | `jarvis.storage.update` rename |
| DELETE | `/jarvis/storage/{file}` | `jarvis.storage.destroy` |
| GET | `/jarvis/storage/{file}/download` | `jarvis.storage.download` |
| POST | `/jarvis/confirmations/{confirmation}/confirm` | `jarvis.confirmations.confirm` (JSON; pending executes once; already resolved returns `already_resolved`) |
| POST | `/jarvis/confirmations/{confirmation}/cancel` | `jarvis.confirmations.cancel` (JSON; duplicate cancel is `already_resolved`) |
| PATCH | `/jarvis/settings/general-prompt` | `jarvis.settings.prompt.update` |
| GET | `/jarvis/workspace/status` | `jarvis.workspace.status` (lightweight tasks/reminders/notifications counts; `/chat/workspace/status` mirror) |

Controllers authorize owner, resolve owned conversations, render Inertia, validate, and call Core services. Logic is not duplicated in the controller.

Shared application service: `PersonalChatSurfaceService` (Cabinet + Workspace). Turn execution remains `ConversationTurnService`.

---

## Layout

`JarvisWorkspaceLayout` — not Admin, not Cabinet.

- Left: conversations (New Chat, **Storage**, local search, title, last activity, selected, overflow menu: rename / confirmed delete)
- Center: thread + sticky composer
- Right / mobile drawer (Owner): compact **Projects** only
- Header: assistant name, AI status dot, Text / Voice, conversation title, Tasks, Reminders, Notifications, **Настройки**, optional Admin / Projects toggle

Memory, Integrations, General Prompt, productivity preference forms, and voice catalog live in **Settings**, not on the main chrome.

Wide screens (1280–2560). Sidebar and context collapse on smaller widths. Composer stays usable on a phone browser.

---

## Text chat

Send path: Workspace → `PersonalChatSurfaceService` → `ConversationTurnService` → Conversation AI → tools → persisted assistant message.

Composer: multiline, Enter send, Shift+Enter newline, UUID `client_message_id`, local draft, disabled while in flight. Paperclip accepts screenshots **and** persistent text files. Drag/drop, Ctrl/Cmd+V screenshot (text paste is not hijacked). One turn may include text + images + Storage files. Limits come from `chatAttachments` and `jarvisStorage` Inertia props. Wide-pointer browsers restore composer focus after send; touch/phone browsers do not force the keyboard. See [STORAGE.md](../STORAGE.md).

Thinking state: `Jarvis is thinking...` (no token streaming in M22). Frontend message `status`: `pending` | `streaming` | `completed` | `failed` so streaming can replace the thinking row later.

Assistant bodies render sanitized Markdown (lists, **code fences with Copy**, **artifact fences with Copy**, tables, http(s) links). Code ≠ artifact. Artifact fence: ` ```artifact Title `. Copy uses raw text. No raw HTML execution. No internal system prompts. No raw tool JSON.

User history: ephemeral screenshot thumbnails (“Temporary image · expires in ~24h”); after purge a “Screenshot expired” card with visual summary. Persistent files show “Saved to Storage”. Click screenshot opens a session-auth lightbox. No public attachment URLs.

Empty chat: «Чем займёмся?» + suggestion chips that send ordinary user text.

---

## Confirmations

Workspace Confirm / Cancel call `ToolConfirmationService` through the same turn path (`да` / `отмена`) so Gmail send, Calendar destructive, and GitHub writes keep existing confirmation policy.

Cards show action summary, provider/tool family, safe preview (Gmail recipients/subject/body; Calendar/GitHub bounded argument preview), expiry when present. Encrypted args are not exposed.

---

## Personal vs technical settings

Workspace Settings (structured panel, `?settings=` allowlist):

- **Profile** — name, email display, timezone, onboarding / Знакомство, password, logout
- **Assistant** — current identity/behavior (read-only from profile) + User General Prompt
- **Memory** — facts/topics counts and last analysis; no raw tables for regular users
- **Productivity** — Daily/Evening/Weekly briefs, proactive, Web Push
- **Voice** — curated assistant voice (`users.voice_id`)
- **Integrations** — Owner: Google / GitHub / Telegram / Web Research status cards linking to Admin; regular user: Telegram pairing only

Admin (technical):

- provider API keys
- model selection
- OAuth connect/disconnect
- workers / webhook / system integrations

Workspace does not reproduce OAuth forms or AI provider settings.

Foreground chat turns refresh task/reminder/notification badges and open panels without F5 (`refreshProductivity` → `workspace.status`). Background scheduler events still rely on Push + next panel open.

---

## Voice (M23 runtime + Gemini STT + Orb + push-to-talk)

Text / Voice toggle keeps the selected conversation. Clicking Voice primes microphone + AudioContext and creates the session. The only capture mode is «Рация»: hold the large button to record, release to send. Silence does not auto-submit. The separate mic button is mute. After TTS, the session waits for the next held turn. Same frontend on `/jarvis` and `/chat`.

Workspace settings include **Voice** for every owner/user. Six curated voices are grouped as three female (Jessica, Sarah, Lily) and three male (Eric, George, Chris). Selection is validated and stored in `users.voice_id`; it applies to both Web Voice and Telegram TTS. Provider/key configuration remains owner-only.

`JarvisVoiceOrb` remains provider-neutral. Ordinary users do not see a Gemini vendor label.

Orb rendering is intentionally brighter on narrow mobile Web screens (about +50%) than desktop (+12%); the CSS fallback follows the same responsive rule.

Switching Voice → Text ends the active `voice_session` and shows the same message thread. Changing `conversationId` while Voice is active ends the old session without uploading pending audio, then starts a new session for the new chat.

Demo visualization: `?voice_demo=1` or `VITE_VOICE_DEMO_MODE`. Not live TTS.

See [VOICE_ARCHITECTURE.md](../VOICE_ARCHITECTURE.md) and [CLIENTS/VOICE_UI.md](VOICE_UI.md).

---

## Payload

Initial Inertia props are bounded: safe user profile, compact conversation list, selected chat, recent messages, compact Owner projects, `settingsContext` (memory summary, allowed integrations, Telegram pairing), personal settings, productivity counts.

No credentials, system prompts, tool logs, or raw group archive.

---

## Out of scope (still)

- Public versioned Client API ([CLIENT_API.md](CLIENT_API.md))
- Telephony / Twilio
- Workspace-specific chat tables (attachments live in Core `message_attachments`; persistent files in `stored_files`)
- Telegram photo ingestion (same attachment table later)
- Streaming, delete-chat
- Historical image byte replay into later turns
- Permanent screenshot library / “save image to Storage”
- ContextBudgetManager (done in M22.3)
