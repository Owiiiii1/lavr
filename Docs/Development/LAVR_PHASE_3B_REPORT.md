# LAVR Phase 3B — Telegram WebApp UX

**Date:** 2026-09-09  
**Branch:** `main`  
**Remote:** `Owiiiii1/lavr` only  
**Public URL:** https://lavr.youngfashionshow.com  
**Commit intent:** `feat: complete LAVR Telegram WebApp UX`

Status vocabulary: [CURRENT_STATE.md](../CURRENT_STATE.md). Phase 3A foundation record is unchanged: [LAVR_PHASE_3A_REPORT.md](LAVR_PHASE_3A_REPORT.md).

No secrets, tokens, webhook secrets, or access codes are recorded here.

---

## Result

Phase 3B brings the existing Telegram Mini App / WebApp to a production-usable CEO mobile UX on the **same** Workspace. No new domain tables. No second frontend. No Conversation Engine rewrite. Webhook path and bot token were not changed.

Real Telegram-client E2E, Menu Button on a phone, and live pairing **remain NOT VALIDATED** because production MySQL `lavr` still has **zero** `telegram_bot_settings` rows and **zero** Telegram `channel_identities`. The existing LAVR bot must be reused; a second bot was not created.

---

## Telegram bot identity

| Item | Status |
| --- | --- |
| Intended bot | Existing LAVR Telegram bot (single bot; do not create another) |
| Bot username in MySQL | Empty (`telegram_bot_settings` = 0 rows) |
| Bot token in MySQL | Absent (do not invent or copy from other projects) |
| Webhook | Not changed in this phase. `telegram:set-webapp-menu` does not call `setWebhook`. |
| Menu Button in Telegram | NOT VALIDATED (command not executed: no token) |
| Owner Telegram pairing | NOT VALIDATED (`channel_identities` Telegram = 0) |

---

## Pairing status

WebApp auth logs in only a **linked active Owner**. It does not pair and does not create users.

Staff pairing remains the existing Telegram Chat flow: Owner sends `/start` to the existing bot, then the Owner `access_code` from LAVR settings. Do not print the code here.

Until a Telegram identity exists for Owner `id=1`, Mini App HMAC success still lands on the not-linked screen.

---

## Menu Button status

Prepared command (unchanged webhook):

```bash
php artisan telegram:set-webapp-menu
```

Target (when a token exists):

- Text: `Open LAVR`
- URL: `https://lavr.youngfashionshow.com/telegram/webapp`

**Not executed in Phase 3B.** Running it now fails with `Telegram bot token is not configured.` and does not insert an empty settings row (`existingSetting()` is `first()`, not `firstOrCreate`).

BotFather equivalent, after the existing token is saved in Admin Integrations:

1. Open the **existing** LAVR bot in @BotFather.
2. Bot Settings → Menu Button.
3. Text `Open LAVR`.
4. URL `https://lavr.youngfashionshow.com/telegram/webapp`.
5. Do **not** rotate the token. Do **not** change webhook.

---

## Exact Owner steps (required for real E2E)

Cursor cannot complete live Mini App validation without the existing bot token and Owner pairing. Do this on the **current** bot only:

1. Sign in at `https://lavr.youngfashionshow.com` as Owner.
2. Admin → Integrations / Telegram settings → save the **existing** LAVR bot token (`POST /settings/telegram/token`, route `settings.telegram.save-token`).
3. Use **Check bot** only. Do not rotate the token. Do not press set-webhook unless webhook is already known missing (Phase 3B did not change webhook).
4. In Telegram, open the existing LAVR bot. Send `/start`, then the Owner access code from LAVR settings (existing pairing).
5. On the server: `php artisan telegram:set-webapp-menu` **or** BotFather Menu Button as above.
6. In Telegram mobile: tap **Open LAVR**. Confirm Today, Chat, nav, BackButton, keyboard, light/dark.

---

## Routes (CURRENT)

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/telegram/webapp` | Boot (guest) |
| POST | `/telegram/webapp/session` | HMAC `initData`, throttle `telegram-webapp` |
| GET | `/lavr/today` | CEO Today |
| GET | `/lavr` | Chat (existing Conversation Engine) |
| GET | `/lavr/people` | Phase 4 placeholder |
| GET | `/lavr/projects` | Current Project work containers |
| GET | `/lavr/more` | Notifications, Reports, placeholders, profile settings |
| GET | `/lavr/notifications` | Existing Notification Center, mobile page |
| GET | `/lavr/reports` | Existing scheduled reports, mobile page |
| GET | `/lavr/meetings` | Phase 5A placeholder |
| GET | `/lavr/commitments` | Phase 6 placeholder |
| POST | `/telegram/webhook` | Unchanged |
| GET | `/register` | 404 |

Internal route names remain `jarvis.*`.

---

## Auth result (automated)

| Case | Automated |
| --- | --- |
| Linked Owner + valid HMAC → session + `/lavr/today` | PASS |
| Unknown Telegram user → not_linked, no User created | PASS |
| Invalid HMAC | PASS |
| Expired `auth_date` | PASS |
| Missing bot token → unavailable, no User created | PASS |
| Arbitrary `next` URL ignored; allowlisted `start_param` honored | PASS (`people`, `notifications`, `reports`) |
| Browser `/` + `/register` 404 | PASS |

Real Telegram `initData` from a phone: **NOT VALIDATED**.

---

## Mobile UX changes

- Bottom nav: Today, Chat, People, Projects, More (five tabs). Active tab uses `aria-current`. More also matches Notifications/Reports/placeholders.
- Chat uses `LavrAppShell fill` so composer sits in the shell column; bottom nav hides while the keyboard is open (`--lavr-keyboard-inset`, `.lavr-keyboard-open`).
- Telegram viewport height follows `viewportHeight` when present so the composer tracks the keyboard. Safe area still uses `--lavr-safe-top` / `--lavr-safe-bottom` plus `env(safe-area-inset-*)`.
- BackButton: hidden on `/lavr` and `/lavr/today`; nested screens show it and use `history.back()`, fallback `/lavr/today`. Syncs on Inertia URL changes. Standalone browser history is unchanged.
- MainButton: still hidden globally. Bridge kept.
- Light Telegram theme: `html.lavr-tg-light` so Workspace `text-white` cards do not go white-on-white. LAVR identity (sky accents) remains.
- Boot copy is non-technical (loading / authenticating / invalid / unavailable). Not-linked explains Settings pairing and may show Open Telegram chat when `bot_username` exists.
- Today: header (LAVR, date, short context), Attention, Calendar (primary Google calendar only, local error if API fails), tasks/reminders, reports, Quick Ask. Section error boundary. No fake Commitments.
- Projects: current model cards + `updated_at` when present.
- More: Notifications, Reports, Meetings/Commitments placeholders, profile/knowledge settings. Full admin Integrations stay in standalone Settings.

---

## Deep links / Open in LAVR

Allowlisted `start_param` values include `today`, `chat`, `people`, `projects`, `more`, `notifications`, `reports`, `meetings`, `commitments`, plus `chat_{id}`, `project_{id}`, `commitment_{id}`. External URLs are rejected.

Key outbound Telegram notifications (not ordinary Chat replies) may attach an inline WebApp button via `TelegramWebAppOpenButton` + `TelegramWebAppUrl::httpsEntry($startParam)`:

- Reminder delivery → `today`
- Scheduled report dispatch → `reports`

Ordinary DM replies do **not** get the button.

---

## Regression checks

| Surface | Automated / HTTP | Real Telegram client |
| --- | --- | --- |
| WebApp session + navigation | PASS (feature tests) | NOT VALIDATED |
| `/register` 404 | PASS | n/a |
| Guest `/lavr/*` → login | PASS | n/a |
| Owner Chat workspace HTML | PASS | NOT VALIDATED |
| Telegram `/start`, DM, voice, webhook live | Not exercised; code path unchanged | NOT VALIDATED (no token/pairing on this host) |
| Standalone login page | curl 200 | Browser click-through NOT VALIDATED (no browser MCP) |

---

## Automated tests

```text
php artisan test --compact \
  tests/Unit/Telegram/TelegramWebAppInitDataValidatorTest.php \
  tests/Unit/Telegram/TelegramWebAppDeepLinkTest.php \
  tests/Unit/Telegram/TelegramWebAppOpenButtonTest.php \
  tests/Unit/Telegram/TelegramWebAppUrlTest.php \
  tests/Feature/TelegramWebAppAuthTest.php \
  tests/Feature/TelegramWebAppNavigationTest.php \
  tests/Feature/TelegramWebAppMenuCommandTest.php
```

`TelegramPairingTest` still depends on a real bot settings row. Empty `telegram_bot_settings` is pre-existing, not a webhook deletion.

---

## MANUAL PASS

What was actually checked on this host:

| Check | Result |
| --- | --- |
| `GET https://lavr.youngfashionshow.com/telegram/webapp` | HTTP 200 boot page (curl) |
| `GET /lavr/today` as guest | Redirect to login (curl / feature test) |
| `GET /register` | 404 |
| `GET /` login branded LAVR | HTTP 200 (curl) |
| `php artisan telegram:set-webapp-menu` without token | Fails as designed; no webhook call; no empty settings insert |
| Feature/unit tests listed above | PASS after this slice |
| Vite production build | Run as part of this slice |

## NOT VALIDATED

| Check | Why |
| --- | --- |
| Mini App opens inside Telegram iOS/Android | No bot token + no Owner Telegram identity in MySQL `lavr` |
| Real `initData` HMAC against production bot | Same |
| Menu Button visible in Telegram client | Command/BotFather not applied (no token) |
| BackButton, keyboard, notch, home indicator, Android/iPhone WebView | Needs real client |
| Telegram light/dark in WebView | CSS prepared; not seen on a phone |
| Open in LAVR on a live reminder/report message | Needs bot send path with token |
| `/start`, inbound/outbound voice, webhook health live | Settings empty; not exercised |
| Standalone desktop click-through (login → Chat → Settings → logout) | No browser MCP in this session; feature tests cover authenticated Inertia payloads |

Do not treat code-only checks as a Telegram client PASS.

---

## Known issues

- Production Telegram bot settings and Owner pairing are still empty. Mini App cannot authenticate a real user until the Owner completes the steps above.
- `TelegramBotManager::setting()` still uses `firstOrCreate([])` for Admin save flows. WebApp/menu/URL paths use `existingSetting()` so they do not insert an empty row.
- Today calendar reads **primary** only. If Google is disconnected or the API fails, Today still renders other blocks.
- MainButton has no product scenario; it stays hidden.
- People / Meetings / Commitments remain placeholders (no mock rows).

---

## Next recommended phase

1. Owner: save existing bot token + pair Telegram via `/start` (exact steps above).
2. Run `php artisan telegram:set-webapp-menu` or BotFather Menu Button; confirm Mini App on a phone.
3. Then **Phase 4** People / Organizations / Projects as business contexts — not before.

Zoom remains TARGET Phase 5B. Executive Brief remains Phase 8.
