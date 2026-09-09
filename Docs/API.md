# HTTP surface (Web)

> **CURRENT routes.** Product surfaces: [INTERFACES.md](INTERFACES.md). Workspace prefix `/lavr`.

**Status.** Actual Laravel/Inertia session routes. There is **no** public versioned Client API. That work is **DEFERRED** ([CLIENTS/CLIENT_API.md](CLIENTS/CLIENT_API.md)).

Telegram uses webhook in-process. Desktop is cancelled.

Do not expose secrets.

---

## Auth

| Method | Path | Notes |
| --- | --- | --- |
| GET/POST | `/` | Login |
| POST | `/logout` | |

Owner landing `/lavr`. No public registration. No ordinary-user workspace.

---

## Personal Workspace

Prefixes: `/lavr` (canonical; named routes remain `jarvis.*`). `/jarvis` and `/chat` redirect to `/lavr`.

- `GET /{prefix}` workspace
- chats store/show/update
- messages store + older
- attachments preview/show
- confirmations confirm/cancel
- settings: prompt, profile, password
- `POST /{prefix}/onboarding/start`
- `GET /{prefix}/reminders`
- `POST /{prefix}/reminders/{reminder}/cancel`
- voice: `sessions` store, show, listen, audio, interrupt, mute, resume, destroy

Owner-only Storage: `/jarvis/storage` index/store/show/update/destroy/download.

---

## `/cabinet`

Compatibility. Index and some show routes redirect to `/jarvis` or `/chat`. A few `cabinet.*` JSON routes still exist. Not the product name.

---

## Admin (owner)

- `/dashboard`
- `/settings` (users, AI, integrations, web research, voice, telegram bot)
- `POST /settings/users` create user
- User Card `/settings/users/{user}` + status/password/prompt/destroy/telegram unlink/access-code/impersonate
- User memory `/settings/users/{user}/memory`
- Google/GitHub OAuth connect/disconnect/callback
- `/projects`, `/telegram-groups`, `/calendar` placeholder
- `POST /impersonation/stop`

---

## Telegram

`POST /telegram/webhook` (CSRF exempt).

---

## Invariants

- Ownership: URL id is not enough
- Clients never send platform prompts or pick an arbitrary vendor
- Access code is not a password
- Voice and reminders are session-authenticated workspace routes
