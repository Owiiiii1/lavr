# LAVR Phase 3A — Telegram WebApp foundation

**Date:** 2026-09-09  
**Branch:** `main`  
**Remote:** `Owiiiii1/lavr` only  
**Public URL:** https://lavr.youngfashionshow.com

Status vocabulary: [CURRENT_STATE.md](../CURRENT_STATE.md).

---

## Result

One LAVR Workspace is the rich UI for standalone browser and Telegram Mini App. Telegram Chat stays the fast channel. No second frontend. No People / Meetings / Commitments tables. Conversation Engine, webhook path, and browser login were not rewritten.

---

## Architecture

```text
Telegram Mini App
  → GET  /telegram/webapp          (guest boot page)
  → POST /telegram/webapp/session  (initData HMAC, throttle)
  → Auth::login(Owner) + session regenerate
  → allowlisted path (default /lavr/today)

Standalone browser
  → GET /  login (admin@admin.com)
  → /lavr  same Inertia Workspace
```

Bot token is read from encrypted `telegram_bot_settings` via `TelegramBotManager`. It is not in `.env` and not in JavaScript.

Deep links: Telegram `start_param` / optional `next` are mapped by `TelegramWebAppDeepLink`. External URLs and non-`/lavr` paths are ignored.

Helper for future Chat buttons: `TelegramChatKeyboard::openLavr($httpsEntry)`. Persistent DM reply keyboard is unchanged.

---

## Telegram auth flow

1. Mini App opens `https://lavr.youngfashionshow.com/telegram/webapp`.
2. `TelegramWebAppBridge` loads `telegram-web-app.js`, calls `ready()` / `expand()`, reads `initData`.
3. Inertia `POST /telegram/webapp/session` with `init_data` (+ optional `start_param`). CSRF as for other web POSTs.
4. Backend: `parse_str` → HMAC-SHA256 (`WebAppData` + bot token) → `hash_equals` → `auth_date` freshness (`telegram.webapp.auth_max_age`, minimum 60s).
5. Resolve `ChannelIdentity` for that Telegram user id. User must be **active Owner**. No new users. No registration.
6. `Auth::login` + `session()->regenerate()`.
7. Redirect to allowlisted `/lavr…` path.

Unknown / non-Owner Telegram account → `Telegram/WebAppBlocked` with copy:

`Telegram account is not linked to this LAVR instance.`

Logs: `outcome` + `telegram_user_id` only. Raw `initData` is not logged.

---

## Security

| Topic | Behavior |
| --- | --- |
| Signature | Server HMAC; frontend user id is not trusted |
| Replay / freshness | `auth_date` max age (default 86400s) |
| Session fixation | regenerate after login |
| CSRF | web middleware; session POST is not CSRF-exempt |
| Rate limit | `throttle:telegram-webapp` (default 20/min by IP) |
| Deep links | allowlist `/lavr`, `/lavr/today`, people/more/projects/meetings/commitments, `/lavr/chats/{id}`, `/lavr/projects/{id}`; query keys notifications/reports/task/reminder/watchers/settings |
| Logout | existing `POST /logout` (More screen) |
| Token | never sent to the browser |

---

## Routes

| Method | Path | Name | Auth |
| --- | --- | --- | --- |
| GET | `/telegram/webapp` | `telegram.webapp.show` | guest |
| POST | `/telegram/webapp/session` | `telegram.webapp.session` | guest + throttle |
| POST | `/telegram/webhook` | `telegram.webhook` | unchanged |
| GET | `/lavr/today` | `jarvis.today.show` | auth |
| GET | `/lavr/people` | `jarvis.people.index` | auth |
| GET | `/lavr/projects` | `jarvis.workspace.projects.index` | auth |
| GET | `/lavr/projects/{project}` | `jarvis.workspace.projects.show` | auth |
| GET | `/lavr/more` | `jarvis.more.show` | auth |
| GET | `/lavr/meetings` | `jarvis.meetings.index` | auth |
| GET | `/lavr/commitments` | `jarvis.commitments.index` | auth |
| GET | `/` | `login` | guest |
| GET/POST | `/register` | — | 404 |

Internal names remain `jarvis.*`.

---

## Telegram SDK bridge

`resources/js/telegram/TelegramWebAppBridge.js` is the only place that should touch `window.Telegram`.

Exposes: `isTelegramWebApp`, `initData`, `startParam`, theme/viewport/safe-area, `ready`, `expand`, `close`, `openLink`, BackButton, MainButton (hidden on boot; not used globally in 3A).

Theme CSS variables: `--tg-theme-bg-color`, `--tg-theme-text-color`, `--tg-theme-secondary-bg-color`, `--tg-theme-button-color`, `--tg-theme-button-text-color` (and hint/link). Applied only when Mini App theme params exist. Standalone keeps the LAVR theme.

Safe area: `env(safe-area-inset-*)` plus `--lavr-safe-top` / `--lavr-safe-bottom` from Telegram insets. Viewport height: `--lavr-viewport-height`.

---

## Navigation / mobile shell

`LavrAppShell` + `LavrBottomNav`: Today, Chat, People, Projects, More.

- Telegram: nav always visible.
- Standalone: nav `lg:hidden` (desktop layout unchanged).
- Chat: existing `PersonalWorkspace` + Conversation Engine; composer stays in the flex column above the nav.
- Telegram BackButton: hidden on `/lavr` and `/lavr/today`; otherwise `history.back()` or Today.

---

## Today

`TodayBriefService` uses existing Task / Reminder / Notification / Scheduled Report panels. No Commitment system. No live Google Calendar fetch (honest hint: ask in chat). Empty states when lists are empty.

---

## Placeholders

| Screen | Honest copy |
| --- | --- |
| People `/lavr/people` | Phase 4; not Knowledge `person` |
| Meetings `/lavr/meetings` | Phase 5 |
| Commitments `/lavr/commitments` | Phase 6; not Tasks / `list_commitments` |
| Projects `/lavr/projects` | Current work containers; not Phase 4 business context |

No fake operational rows.

---

## Config / artisan

`config/telegram.php`: `webapp.path`, `short_name` (`TELEGRAM_WEBAPP_SHORT_NAME`, default `app`), `menu_button_text`, `auth_max_age`, `rate_limit_per_minute`.

`php artisan telegram:set-webapp-menu` — `setChatMenuButton` only. **Does not change webhook. Not executed in Phase 3A.**

---

## Tests

### IMPLEMENTED / PASS

```text
php artisan test --compact \
  tests/Unit/Telegram/TelegramWebAppInitDataValidatorTest.php \
  tests/Unit/Telegram/TelegramWebAppDeepLinkTest.php \
  tests/Feature/TelegramWebAppAuthTest.php \
  tests/Feature/TelegramWebAppNavigationTest.php \
  tests/Feature/LavrSingleUserSurfaceTest.php \
  tests/Feature/IdentityAuthorizationTest.php \
  tests/Feature/BaselineTest.php \
  tests/Feature/Http/Controllers/Jarvis/JarvisWorkspaceControllerTest.php
```

| Case | Result |
| --- | --- |
| Valid initData + linked Owner → redirect `/lavr/today`, session regenerated, user count unchanged | PASS |
| Invalid HMAC | PASS |
| Expired `auth_date` | PASS |
| Unknown Telegram user → `not_linked`, no User row | PASS |
| Arbitrary `next` URL rejected; `start_param=people` still allowlisted | PASS |
| Missing `init_data` → validation error | PASS |
| Guest workspace routes → login | PASS |
| Owner Today / People / More / Projects / placeholders | PASS |
| `/register` 404 | PASS |
| Login page, dashboard, settings | PASS |

### MANUAL PASS (this host, HTTP)

| Check | Result |
| --- | --- |
| `GET /` login branded LAVR | curl 200 |
| `GET /telegram/webapp` boot page | curl 200 |
| `GET /lavr/today` guest | redirect to login |
| `GET /register` | 404 |
| Vite production build includes Today / WebApp / shell chunks | PASS |
| Paid AI calls | not run |

Standalone desktop/mobile viewport click-through in a real browser: **NOT VALIDATED** in this session (no browser MCP). Feature tests cover authenticated HTML/Inertia payloads.

### NOT VALIDATED

| Check | Why |
| --- | --- |
| Real Telegram iOS/Android Mini App | Needs production bot token + Owner Telegram pairing in MySQL `lavr` |
| Menu Button `Open LAVR` | BotFather or `telegram:set-webapp-menu` not run |
| Telegram DM / voice live | Not exercised; webhook code path unchanged |
| Keyboard / iPhone home indicator in Telegram WebView | Needs real client |

`tests/Feature/TelegramPairingTest.php` currently fails because MySQL `lavr.telegram_bot_settings` has **zero rows** (no webhook secret). Phase 3A tests mock the bot manager and do not write that table. This is a **pre-existing empty settings row**, not a webhook route deletion.

---

## BotFather / manual requirements

Do this only when the live bot token is in Admin → Settings → Telegram (encrypted `telegram_bot_settings`) and webhook is already healthy.

1. @BotFather → bot → **Bot Settings → Menu Button**.
2. Text: `Open LAVR`.
3. URL: `https://lavr.youngfashionshow.com/telegram/webapp` (HTTPS, no token in URL).
4. Mini App short name should match `TELEGRAM_WEBAPP_SHORT_NAME` (default `app`) if using `https://t.me/<bot>/<short_name>?startapp=…`.
5. Optional, after token is present: `php artisan telegram:set-webapp-menu` (still does **not** call `setWebhook`).

Do **not** rotate the bot token. Do **not** change webhook URL.

---

## Known limitations

- MySQL `lavr.telegram_bot_settings` is empty on this host at Phase 3A close. Mini App HMAC cannot succeed against the real bot until the existing Settings UI saves the token. Owner `channel_identities` for Telegram is also empty here — WebApp will show the not-linked screen until pairing.
- Today calendar is a hint, not a live Google list.
- MainButton unused.
- “Open in LAVR” is a keyboard helper; Chat messages do not auto-attach it.
- Route cache was rebuilt after adding routes (`php artisan route:cache`).

---

## Next recommended slice

1. Confirm Telegram token + Owner pairing in MySQL (existing Admin / `/start` pairing). Do not invent a new user.
2. BotFather Menu Button (or opt-in artisan command).
3. Open Mini App on a phone: Today, Chat, People placeholder, Projects, More, logout.
4. Optional: attach `openLavr()` to selected Telegram notifications only.
5. Phase 4 People tables — not before.

---

## Files (summary)

Backend: `TelegramWebAppController`, `TelegramWebAppInitDataValidator`, `TelegramWebAppAuthenticator`, `TelegramWebAppDeepLink`, `TelegramWebAppUrl`, `TodayBriefService`, workspace page controllers, `config/telegram.php`, `telegram:set-webapp-menu`.

Frontend: `TelegramWebAppBridge`, `LavrAppShell`, `LavrBottomNav`, `Telegram/WebAppBoot`, `Telegram/WebAppBlocked`, `Jarvis/Today|People|More|Projects|ProjectShow|ComingFoundation`.
