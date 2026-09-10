# Integrations and Tool Layer

> **CURRENT tool/OAuth layer.** Product source map (mailboxes, Zoom, project binding): [DATA_SOURCES.md](DATA_SOURCES.md).

Внешние сервисы не живут внутри Telegram adapter и не вызываются из Inertia. Conversation Engine запрашивает capability; Integration Layer исполняет.

```
Conversation Engine
  → Tool Registry
    → ToolExecutionService (policy + logs)
      → Core tool | Integration provider adapter
```

**Status (M22.3 / M22.3.1):** IMPLEMENTED (not live-validated) — Integration Registry, encrypted `integration_accounts`, `tool_execution_logs`, persisted `tool_confirmations`, owner Integrations Admin, Google OAuth (identity + incremental Calendar + Gmail scopes), **Google Calendar tools**, **Gmail tools**, **GitHub OAuth App + GitHub tools**, **Web Research** (`search_web` / `fetch_web_page` via `WebSearchManager` → `WebSearchProvider`: `gemini_google` / `tavily` / `disabled`; Admin settings in `web_research_settings`; fetch via `WebPageFetchService`). ElevenLabs API is **not** implemented. Automated tests and live Google/GitHub/web/AI smoke are deferred by Owner. Identity or Calendar connected ≠ Gmail ready. Missing Gmail scopes do not call the Gmail API. Missing GitHub OAuth env → Not configured; no GitHub API calls. Disabled/unconfigured search → `web_search_disabled` / `web_search_not_configured`.

Conversation Engine не импортирует Google SDK, ElevenLabs SDK, Telegram SDK, или Tavily HTTP.

---

## Кто имеет доступ

Google / ElevenLabs / GitHub / Integrations admin — **owner only** (`integrations_admin`). GitHub tools also require capability `github` (owner default `*`). Web Research requires `web_research` (owner default `*`; not granted to regular users in M22.3). ADR-028, ADR-095.

Обычный `user` не видит Integrations, не получает Gmail/Calendar/GitHub/Storage/web/voice tools, не читает credentials.

**Reminders / Tasks** — не этот слой и не Calendar. Core Reminder Engine и Task Engine доступны owner и users. [REMINDERS.md](REMINDERS.md), [TASKS.md](TASKS.md).

Проверка permission в Tool Layer / Core, не в UI.

---

## Registry vs accounts

- **Code `IntegrationRegistry`** — available providers and capabilities.
- **DB `integration_accounts`** — connected account state and encrypted credentials.

Не хранить классы провайдеров в DB.

Зарегистрированные keys сегодня: `google`, `telegram`, `elevenlabs`, `github`, `zoom`.

| Provider | Status | Source of truth |
| --- | --- | --- |
| Google | OAuth identity + Calendar + Gmail tools (M19) | `integration_accounts` encrypted credentials |
| ElevenLabs | placeholder Not configured | `integration_accounts` later |
| Telegram | status bridge | existing `telegram_bot_settings` — **no token copy** |
| Zoom | S2S OAuth + webhook ingest (Phase 5B, live E2E not validated) | `integration_accounts` encrypted credentials |

Telegram integration card never writes `integration_accounts.credentials_encrypted`. ADR-061.

---

## Settings → Integrations (Owner Admin)

Owner-only (`/settings?tab=integrations`, also `/settings/integrations`). Subsections:

- **Overview** — Google / GitHub / ElevenLabs status cards; compact tiles that open Telegram, Web Research, Voice/Speech, and Activity.
- **Web Research** — provider, enablement, limits, Tavily key. Not on the overview form.
- **Voice / Speech** — STT provider, TTS provider, configured/not configured, ElevenLabs key status. Not Conversation AI. No Test Connection. No plaintext secrets.
- **Telegram** — bot token and webhook. Legacy `?tab=telegram` opens this subsection.
- **Activity** — Recent Tool Executions (time, tool, provider, status, duration, safe error code; no arguments/result bodies). Limit `config/integrations.php` `recent_executions_limit` (50). Retention TBD.

Cards: Google (Connect / Reconnect / Disconnect / Enable Calendar / Enable Gmail; Identity vs Calendar vs Gmail capability states; Not configured if env missing), GitHub (Connect / Reconnect / Disconnect; login; scopes; Not configured if env missing), ElevenLabs (configured status from Voice settings; no key on the overview card). Connected Google is not automatically Gmail-enabled. No Gmail inbox admin UI. No GitHub PAT field. GitHub card does not call GitHub on page load.

Normal user: 403 on Admin Integrations. No user-facing Integrations admin and no Google/GitHub/Web Research cards in Workspace Settings.

Workspace Settings → Integrations:

- **Owner:** compact status cards (Google, GitHub, Telegram bot, Web Research) with Connect/Manage into Admin. Personal Telegram pairing is a separate card.
- **Regular user:** Telegram pairing / connected-as only. Owner integrations are omitted even if the user has `web_research` runtime capability.

Backend capabilities remain authoritative. Secrets are never returned to Workspace.

---

## Credentials

`integration_accounts.credentials_encrypted` uses Laravel `encrypted:array`. Adapter-only getter. Hidden from `toArray` / JSON / Inertia. Never logged.

Core does not know Google token field names. Envelope is provider-specific inside the adapter.

---

## Tools

| Tool | Class | Provider |
| --- | --- | --- |
| `create_reminder` | write (core) | null |
| `list_reminders` | read (core) | null |
| `update_reminder` | write (core) | null |
| `snooze_reminder` | write (core) | null |
| `complete_reminder` | write (core) | null |
| `cancel_reminder` | write (core) | null |
| `search_conversation_history` | read | null |
| `get_project_context` | read | null |
| `search_group_knowledge` | read | null |
| `list_google_calendars` | read | google |
| `list_calendar_events` | read | google |
| `get_calendar_event` | read | google |
| `search_calendar_events` | read | google |
| `google_calendar_freebusy` | read | google |
| `create_calendar_event` | write | google |
| `update_calendar_event` | write | google |
| `delete_calendar_event` | destructive | google |
| `search_gmail` | read | google |
| `list_gmail_messages` | read | google |
| `get_gmail_message` | read | google |
| `get_gmail_thread` | read | google |
| `list_gmail_labels` | read | google |
| `create_gmail_draft` | write | google |
| `send_gmail_message` | write (always confirm) | google |
| `modify_gmail_labels` | write | google |
| `list_github_repositories` | read | github |
| `get_github_repository` | read | github |
| `list_github_branches` | read | github |
| `list_github_commits` | read | github |
| `get_github_commit` | read | github |
| `compare_github_refs` | read | github |
| `get_github_file` | read | github |
| `search_github_code` | read | github |
| `list_github_issues` | read | github |
| `get_github_issue` | read | github |
| `list_github_pull_requests` | read | github |
| `get_github_pull_request` | read | github |
| `get_github_pull_request_diff` | read | github |
| `list_github_workflow_runs` | read | github |
| `get_github_workflow_run` | read | github |
| `create_github_issue` | write | github |
| `comment_github_issue` | write | github |
| `create_github_branch` | write | github |
| `create_github_pull_request` | write | github |
| `confirm_tool_action` | write (core) | null (only when a pending confirmation exists) |
| `cancel_tool_action` | write (core) | null (only when a pending confirmation exists) |

Calendar tools require capability `google_calendar` (owner). Gmail tools require capability `gmail` (owner). GitHub tools require capability `github` (owner). Definitions stay available when disconnected; runtime returns `google_not_connected` / `calendar_scope_required` / `gmail_scope_required` / `github_not_connected` / `github_scope_required`. Normal users do not receive Calendar, Gmail, or GitHub tools. Voice is a conversation modality, not a tool.

`ToolExecutionService` wraps every execute: resolve → capability → confirmation policy → log → run → finalize. Multi-step loop is unchanged (max 5 rounds).

Model cannot pass `authorized`, `confirmation`, `user_id`, or `integration_account_id` as rights.

---

## Confirmation policy

| Класс | Решение |
| --- | --- |
| Read | allowed |
| Core write (`create_reminder`, `update_reminder`, `snooze_reminder`, `complete_reminder`, `cancel_reminder`) | allowed (existing explicit-request UX) |
| External write + `explicitUserCommand=true` | allowed (except always-confirm tools) |
| External write + model-proposed / unknown | confirmation_required |
| `send_gmail_message` (`alwaysConfirm`) | confirmation_required even on an explicit «отправь» |
| Destructive | confirmation_required |

`ToolExecutionContext.explicitUserCommand` is set by the application layer. User-initiated conversation turns currently set `true`. Precise NLP intent detection can evolve later.

Confirmation result: `error=confirmation_required` + human summary + `confirmation_id`. A `tool_confirmations` row is persisted (encrypted arguments, TTL 10 minutes, user+conversation bound).

Destructive Calendar delete always requires confirmation, even when the user wrote «удали». Gmail send always requires confirmation, even when the user wrote «отправь». Pending send summary/preview shows recipients, subject, and a bounded body preview — no tokens. Draft create is allowed on an explicit command and confirmation-required when model-proposed. Draft ≠ send. Model cannot invent a token or self-confirm. Application layer accepts only conservative phrases (`да` / `yes` / `confirm` / `подтверждаю` / `удалить`) or Web/Telegram Confirm buttons (they send `да`). Cancel: `нет` / `no` / `cancel` / `отмена`. Expired or cancelled rows cannot execute. Confirmed execute is one-time (send idempotency). Duplicate Confirm/Cancel on an already resolved row returns `already_resolved` without a second external action. Workspace Text and Voice share one confirmation record: only `status=pending` is actionable; confirmed/executed/cancelled/expired cards keep history without Confirm/Cancel, and Voice overlay never shows a resolved card.

`confirm_tool_action` / `cancel_tool_action` are exposed only while a pending row exists and still require the server-side affirmative/cancel signal.

---

## Execution logs

`tool_execution_logs`: started/succeeded/failed/denied/confirmation_required. Metadata only safe counts/error codes. No tokens, keys, email bodies, transcripts, or raw arguments.

Calendar and Gmail tool success/failure updates `last_used_at` / `last_success_at` / `last_error_*` on the Google account. GitHub tools update the same fields on the GitHub account. Core tools leave `integration_account_id` null. Metadata may include `result_count`, `operation`, `truncated`, `confirmation_id`, and GitHub `repository` full_name — never tokens, file contents, issue/PR/comment bodies, or diffs.

---

## Google OAuth (M17)

Owner-only (`integrations_admin`). Routes:

- `POST /settings/integrations/google` — save OAuth client configuration (`settings.integrations.google.update`)
- `GET /settings/integrations/google/connect` — starts OAuth (`integrations.google.connect`)
- `GET /integrations/google/callback` — Google redirect (`integrations.google.callback`)
- `POST /settings/integrations/google/disconnect` — CSRF (`integrations.google.disconnect`)

Default callback URL: `{APP_URL}/integrations/google/callback`. Override with Admin Redirect URI, else `GOOGLE_REDIRECT_URI`. Must match Google Cloud Console exactly.

### OAuth client configuration

Admin: Settings → Integrations → Overview → Google → **Google Configuration**.

Fields: Client ID, Client Secret, Redirect URI. Save Google configuration.

Storage: singleton `google_oauth_settings`. Client Secret is an encrypted column (`encrypted` cast), never returned to HTML/JSON/Inertia. Client ID and Redirect URI are not secrets.

Precedence (runtime, independent of config cache):

1. Admin DB value if present
2. `.env` / `config('integrations.google.*')` fallback
3. Redirect URI computed default: `{APP_URL}/integrations/google/callback`

Empty Client Secret on Save keeps the stored secret. Env secrets are not copied into the DB when the page is opened.

Statuses:

- **Not configured** — no Client ID+Secret from DB or env; Connect Google disabled
- **Configured** — OAuth client credentials present; Google account not connected
- **Connected** — OAuth account exists (tokens remain in `integration_accounts.credentials_encrypted`)

### Env fallback

```
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=
```

`.env` remains supported. Do not paste secrets into docs or Cursor reports. After Admin save, `config:clear` is not required for DB values.

If missing: card **Not configured**, Connect disabled, connect route safe error.

### Scopes (least privilege)

Identity connect requests only: `openid`, `email`, `profile`.

Calendar incremental connect (`?intent=calendar`) adds `https://www.googleapis.com/auth/calendar` (list + events + freebusy + write).

Gmail incremental connect (`?intent=gmail`) adds, and does **not** request `mail.google.com`:

- `https://www.googleapis.com/auth/gmail.readonly` — search / read / list / labels
- `https://www.googleapis.com/auth/gmail.compose` — drafts and send
- `https://www.googleapis.com/auth/gmail.modify` — labels, mark read/unread, archive

Runtime checks: read = readonly or modify; draft = compose; send = compose or send; labels = modify. Card “Gmail enabled” requires all three requested Gmail scopes.

`include_granted_scopes=true`. Stored scopes = union of previously granted identity + Calendar + Gmail. Refresh token is preserved. Identity or Calendar scopes are not dropped.

UI labels: Identity / Email identity / Profile / Calendar / Gmail read / Gmail compose / Gmail modify / Gmail send.

### Flow

1. Owner Connect → Authorization Code + PKCE S256 + `access_type=offline`.
2. `prompt=consent` only when no usable refresh token (first connect / revoked).
3. Session state: random, owner id, PKCE verifier, TTL 10 minutes, one-time. No arbitrary return URL.
4. Callback requires authenticated owner. Invalid/expired/used state → reject, no token exchange.
5. Token exchange + OpenID userinfo. Identity = Google `sub` (not email). Email stored as label.
6. Upsert account. Same `sub` updates the row. A different `sub` disconnects the previous active account (one active Google account for MVP).
7. Envelope: `access_token`, `refresh_token`, `expires_at`, `token_type`. `id_token` is not stored.
8. If Google omits `refresh_token` on reconnect, the existing refresh token is kept.

### Refresh

`GoogleCalendarService` and `GoogleGmailService` **must** call `GoogleCredentialService::getValidAccessToken($account)`. Do not read `credentials_encrypted` in Core or tools.

- Refresh when `expires_at` is within `refresh_skew_seconds` (default 120).
- `lockForUpdate` prevents parallel refresh.
- Expired access + valid refresh = still **Connected**.
- `invalid_grant` → status `revoked`, credentials wiped, Reconnect required.
- Integrations page does **not** call Google on load.

`last_success_at` updates on connect/refresh. `last_used_at` remains for future API/tool use.

### Disconnect

Attempt Google revoke, then always wipe local credentials. Remote revoke failure still disconnects locally (warning flash). Email/`sub` remain on the row for reconnect metadata.

OAuth admin actions are **not** written to `tool_execution_logs`.

### Google Cloud manual setup

1. Create/select a Google Cloud project.
2. Configure OAuth consent screen (Testing vs Production; Testing refresh tokens may expire per Google policy).
3. Create OAuth client type **Web application**.
4. Authorized redirect URI: exact Jarvis callback (`/integrations/google/callback`).
5. Put Client ID and Client Secret in Admin → Settings → Integrations → Google Configuration (preferred), or in server env. Do not commit them.
6. Authorized redirect URI in Google Cloud must match the effective Jarvis callback (Admin field, else `GOOGLE_REDIRECT_URI`, else `{APP_URL}/integrations/google/callback`).
7. Owner: Settings → Integrations → Google → Connect → consent → card shows Connected + email → reload → Disconnect → Reconnect.

Enable the Google Calendar API and the Gmail API in Google Cloud before live smoke. Owner does not have to enable them during M19 implementation.

Combined live Google smoke is still deferred. Cursor does not connect real Google, read a production inbox, send mail, create drafts, or change labels.

### Google Calendar (M18)

Live Google Calendar is the source of truth. No local `calendar_events` cache, no sync cron, no webhook.

Adapter: `GoogleCalendarService` via Laravel HTTP client (`config/google_calendar.php` bounds and timeouts). Safe GET retry only. Tools never call Google HTTP.

Account resolution: current owner → `IntegrationAccountService` → active Google account. Model cannot pass `integration_account_id`.

Timezone: owner `users.timezone` is the authoritative fallback. Naive datetimes are interpreted in that IANA zone and sent to Google as wall time + `timeZone` (DST-safe). All-day events stay dates (`all_day=true`), not fake midnight meetings.

Create uses a server-derived Google-compatible event id (`jvs` + hex) from user/conversation/tool-call id. The model cannot supply the idempotency key. Same ToolCall retry reuses the id; 409 returns the existing event.

Update is PATCH of supplied fields only. `etag` / If-Match when present; conflict → `calendar_conflict`. Recurrence: read metadata only; no RRULE authoring; update/delete use the explicit instance id. Google Meet / `conferenceData` is not created.

`send_updates`: `none` (default), `all`, `externalOnly`. Use `all` only when the user explicitly asked to invite.

Search/list/freebusy are bounded (config max events, 31-day freebusy, default search window −90 / +365 days). Pagination stops at the cap and sets `truncated`.

Reminder Engine remains a separate subsystem. «Напомни» ≠ Calendar event.

### Gmail (M19)

Live Gmail is the source of truth. No local `emails` / `gmail_messages` / `gmail_threads` tables, no global inbox mirror, no `historyId` / users.watch. Active E.2 Gmail watchers may poll a bounded query (not a mailbox sync). Two Gmail watcher kinds: (1) recurring local-morning **digest** (`source.digest` + `schedule.kind=daily_local`) summarizing new mail since the previous cursor; (2) recurring **event** watcher (`sender` / `senders` / `sender_domains`, no daily schedule) that notifies on each matching new message through the existing Notification Center. First evaluation only establishes a baseline and does not dump history. Both are read-only (no mark-as-read, archive, label, or reply). Event monitoring: **IMPLEMENTED / READY FOR OWNER VALIDATION**.

Adapter: `GoogleGmailService` via Laravel HTTP client (`config/google_gmail.php` bounds and timeouts). `GmailMimeParser` (text/plain first, HTML→text fallback, nested multipart, attachment metadata only, body cap + `truncated`). `GmailMimeBuilder` (To/Cc/Bcc, RFC 2047 subject, text/plain UTF-8, reply headers, base64url). Tools never call Google HTTP.

Account resolution: current owner → `IntegrationAccountService` → active Google account + granted Gmail scope. Model cannot pass `user_id` or `integration_account_id` as rights. Watcher evaluation may pin `watchers.integration_account_id` only after ownership was checked at create; `source_config` cannot smuggle another account id. Remote Gmail message/thread ids may pass through the tool loop and must not be shown in user-facing digest text.

Search/list are bounded. Default list is INBOX. `unread=true` adds `is:unread`. Pagination stops at the cap (`truncated`, `next_page_available`). Thread read is chronological and total-char capped. The service does not summarize; Conversation AI does.

Writes: GET/search/read may retry once. `drafts.create`, `messages.send`, and label modify are **not** auto-retried (avoids duplicate send). Send always uses persisted `tool_confirmations` (one-time execute). Draft retries may create a second draft if the user asks again; Core does not retry the HTTP write.

Reply threading: `threadId` + `In-Reply-To` + `References`. Not a new thread with only `Re:`. No separate reply tool — `create_gmail_draft` / `send_gmail_message` accept `reply_to_message_id` / `thread_id`. The service does not fuzzy-resolve human names.

Labels: mark read = remove `UNREAD`; unread = add `UNREAD`; archive = remove `INBOX`. No trash / permanent delete. Label ids must match Gmail format / known system labels.

Attachments: READ metadata only (filename, mime, size, attachment id). WRITE attachments out of scope. Outbound mail is plain text only.

Gmail tool results are not copied into personal memory automatically. Email → Calendar is a multi-tool Conversation AI loop, not an extraction job.

Safe errors: `google_not_connected`, `gmail_scope_required`, `gmail_message_not_found`, `gmail_thread_not_found`, `gmail_forbidden`, `gmail_rate_limited`, `gmail_send_failed`, `gmail_invalid_recipient`, `gmail_unavailable`, `gmail_conflict`. Raw Google bodies are not returned.

### GitHub (M21)

Owner-only (`github` + `integrations_admin`). Integration Framework tools — не Telegram adapter, не локальный `git`, не client-side token store.

**Status.** IMPLEMENTED, NOT LIVE-VALIDATED. Owner deferred all automated tests and live GitHub smoke. Cursor does not connect a real GitHub account or perform write operations.

Live GitHub is the source of truth. No local `github_repositories` / commits / files / issues / PRs tables. No webhook, polling, scheduled sync, or `git clone`/`git pull`/`git log` on the server.

Adapter: `GitHubApiService` via Laravel HTTP client (`config/github.php` bounds and timeouts). Tools never call GitHub HTTP. Token only via `GitHubCredentialService`. Envelope in `integration_accounts.credentials_encrypted`.

### OAuth App MVP

Routes (owner-only):

- `GET /settings/integrations/github/connect` — `integrations.github.connect`
- `GET /integrations/github/callback` — `integrations.github.callback`
- `POST /settings/integrations/github/disconnect` — `integrations.github.disconnect`

Default callback: `{APP_URL}/integrations/github/callback`. Override: `GITHUB_REDIRECT_URI`.

Env placeholders (`.env.example` only; Cursor does not write real values):

```
GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=
GITHUB_REDIRECT_URI=
```

Scopes: `repo`, `read:org`. `repo` is broad but required for private repository read/write through an OAuth App. Not requested: `admin:org`, `delete_repo`, `admin:repo_hook`, `admin:public_key`, `gist`, `user:email`, `workflow`.

State: cryptographically random, session, owner-bound, TTL, one-time, PKCE S256. Callback requires authenticated owner. Invalid/expired/reused state → reject, no token exchange.

Identity: GitHub numeric user id (`external_account_id`). Email is a label only. Metadata may store login, name, avatar URL, html URL.

If GitHub issues expiring tokens + refresh tokens, the envelope stores `refresh_token` / `expires_at` and `GitHubCredentialService` refreshes. Classic non-expiring tokens (no `expires_at`) remain valid until revoked.

401 / bad credentials → status revoked, credentials wiped, `github_token_revoked`, reconnect required.

Disconnect: attempt remote token revoke; always wipe local credentials. Remote revoke failure still disconnects locally.

Missing env: card **Not configured**, Connect disabled, connect route `configuration_missing`.

GitHub App installation (granular per-repo) may replace/extend this later. M21 = OAuth App MVP. ADR-096.

### GitHub tools

Read: repositories, branches, commits, compare refs, file (text, bounded), code search, issues (PRs filtered from issues list), pull requests, PR diff, workflow runs/jobs summary. No log download.

Write (controlled): `create_github_issue`, `comment_github_issue` (issue or PR number), `create_github_branch` (no overwrite/force/delete), `create_github_pull_request` (no merge). Existing open PR with same head/base is returned instead of duplicating.

Not implemented: merge PR, close/delete repository, force push, delete branch, edit repo settings, write repository files, modify workflows, secrets, releases, tag deletion, collaborator/admin ops.

Confirmation: read allowed. GitHub write + explicit user command = allowed. Model-proposed write = confirmation_required. No `alwaysConfirm` on GitHub writes (reversible/manageable; no merge). Standard external-write policy.

Writes: HTTP retry = 0. Residual duplicate risk if the owner repeats a distinct request. Branch create detects existing exact branch (`github_conflict`).

Reads: bounded GET retry on transient errors. Rate limit → `github_rate_limited` with `reset_at` / `remaining=0` when headers allow. No infinite retry.

Repository argument prefers `owner/name`. Short names resolve: exact full_name → exact name → normalized → bounded candidates. Ambiguous → candidates, never the first guess.

«Что изменилось сегодня» = `list_github_commits(since=today)` then `get_github_commit` / compare — normal multi-tool reasoning, not a special analysis subsystem.

Private repo content may enter Owner Conversation AI only through explicit tools. No automatic prompt injection, no default repo dump, no automatic memory ingestion. No M21 project↔repository DB relation; `get_project_context("JARVIS")` plus a GitHub tool in the same turn is allowed.

Future Web/Voice (and deferred Mobile) use the same server-side tools. Clients must not store GitHub tokens.

Safe errors: `github_not_connected`, `github_scope_required`, `github_repository_not_found`, `github_ref_not_found`, `github_file_not_found`, `github_issue_not_found`, `github_pr_not_found`, `github_workflow_run_not_found`, `github_forbidden`, `github_rate_limited`, `github_validation_failed`, `github_conflict`, `github_unavailable`, `github_token_revoked`. Raw API bodies are not returned to AI/logs.

### Web Research (M22.3 / M22.3.1)

Owner-only capability `web_research`. Tools `search_web` and `fetch_web_page`. Search goes through `WebSearchManager` → `WebSearchProvider` (`gemini_google` / `tavily` / `disabled`). Admin: Settings → Integrations → Web Research. Runtime uses `WebResearchSettingsService` (DB → env/config → defaults, then hard ceilings). `gemini_google` uses the existing Gemini credential in `ai_provider_settings` (Google Search grounding for **discovery** only). Tavily remains an alternative; encrypted Admin key with `WEB_SEARCH_API_KEY` fallback. Fetch is always SSRF-guarded `WebPageFetchService`, never Gemini grounding. Disabled search → `web_search_disabled`. Fetch off → `web_fetch_disabled`. Secrets never returned to Inertia. Workspace Settings → Integrations shows read-only provider status for Owner only. Full spec: [WEB_RESEARCH.md](WEB_RESEARCH.md).

### ElevenLabs / Voice Speech (M23 + M23.2)

Admin: Settings → Integrations → Voice/Speech. TTS adapter `ElevenLabsTextToSpeechProvider`. Encrypted key on `voice_settings` (env `ELEVENLABS_API_KEY` fallback). Overview ElevenLabs card shows configured/not configured only. Selecting TTS/STT does **not** change Conversation AI. **Telegram TTS speed** (`telegram_tts_speed`, default 1.15, range 0.70…1.20) is an Admin-only system setting for Telegram voice replies; Web Voice does not use it. No live Test Connection. Telephony is not implemented.

Provider matrix:

- STT: `none` | `gemini` | `openai`
- TTS: `none` | `elevenlabs`

Gemini STT (`GeminiSpeechToTextProvider`) reuses the existing Gemini credential in `ai_provider_settings` (`GeminiCredentialResolver`). No Voice-specific Gemini key. Configured status is local (provider + connected credential + non-empty model). Recommended: STT = Gemini, TTS = ElevenLabs.

OpenAI Whisper remains an optional adapter on `/audio/transcriptions`. It is not required.

See [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md).

---

## Multi-step tools

Один conversational turn может содержать **несколько** последовательных tool calls. Conversation Engine **не** предполагает `one message = max one tool call`.

---

## Связь с AI roles

- **Owner Conversation AI** — общение owner + tool loop.
- **Default User Conversation AI** — reminder + history search.
- **Owner Analysis AI** — группы/jobs. Не user DM.

ADR-013, ADR-029.
