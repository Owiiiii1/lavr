# LAVR Phase 3C — Ukrainian-first localization

**Date:** 2026-09-09  
**Branch:** `main`  
**Remote:** `Owiiiii1/lavr` only  
**Public URL:** https://lavr.youngfashionshow.com  
**Commit intent:** `feat: add Ukrainian-first localization`

Status vocabulary: [CURRENT_STATE.md](../CURRENT_STATE.md). Phase 3B UX record is unchanged: [LAVR_PHASE_3B_REPORT.md](LAVR_PHASE_3B_REPORT.md).

No secrets, tokens, webhook secrets, or access codes are recorded here.

---

## Result

Phase 3C adds one Owner locale layer for Telegram WebApp and standalone Web. Ukrainian is the default and the fallback. English and Russian are supported. UI language and assistant language are separate settings. There is no second frontend and no multi-user locale architecture.

Webhook path, bot token, pairing, and Menu Button were not changed. People / Meetings / Commitments domain work was not started. Phase 4 was not started.

---

## Locale architecture

| Piece | Location |
| --- | --- |
| Allowed codes | `App\Enums\OwnerLocale` (`uk`, `en`, `ru`); anything else → `uk` |
| Config | `config/locale.php`, `config/app.php` (`APP_LOCALE` / `APP_FALLBACK_LOCALE` default `uk`) |
| Request locale | `SetOwnerLocale` sets `app()->setLocale` from the Owner interface locale (guest → `uk`) |
| Shared Inertia props | `locale`, `assistantLocale`, `supportedLocales` from `HandleInertiaRequests` |
| Frontend catalog | `resources/js/locales/{uk,en,ru}.js` via `useTranslation()` / `t('today.title')` |
| Server Today copy | `lang/{uk,en,ru}/today.php` via `OwnerCopy` |
| Assistant language | `AssistantProfileService::identityContext()` (Conversation Engine / PersonalityPresentationBuilder) |

Web and Telegram WebApp use this same layer. Telegram `language_code` is not stored and is not authoritative. Telegram theme does not change locale.

---

## Storage fields

Nullable strings on existing `user_assistant_profiles`:

- `interface_locale`
- `assistant_locale`

Null is treated as `uk` in code. Reads do not write the default. Invalid values passed to Settings are stored as `uk`.

---

## Default / fallback

| Rule | Value |
| --- | --- |
| Default | `uk` |
| Fallback | `uk` |
| Unsupported locale | `uk` |
| Missing translation key | Ukrainian catalog, then the key plus `owner_locale_missing_key` log (no crash) |

English is not the fallback.

---

## UI switch

Owner Settings → Profile: segmented **UA | EN | RU**. Saves `interface_locale` through `PATCH /lavr/settings/locales`. Login has an ephemeral preview only; it does not persist an Owner setting.

---

## Assistant language

Same Settings card: select Українська / English / Русский. Saves `assistant_locale` separately. UI and assistant locales may differ.

---

## Conversation Engine integration

`identityContext` adds:

- preferred assistant response language;
- reply in that language by default;
- current-request language (explicit English/Russian, or “відповідай англійською”) may override **this turn only**;
- do not change the stored preference unless the Owner changes Settings or asks to change the setting permanently;
- do not translate source artifacts when storing or quoting them.

There is no separate language-detection service.

---

## Translated surfaces

Owner-facing Workspace copy covered in this phase:

- Telegram WebApp boot and blocked/error states
- bottom navigation
- Today (including locale-aware `date_label`)
- Chat shell labels
- People placeholder
- Projects
- More
- Notifications
- Reports
- Settings language block and profile chrome
- Login owner-facing copy
- common buttons / errors / empty states

---

## Untranslated technical / admin surfaces

Admin kit, dashboard widgets, technical Settings integrations, onboarding “Знакомство” leftover copy, and `HumanMoment` relative-day labels (still Russian) are **not** fully translated. That is intentional for Phase 3C.

---

## Date formatting

Today `date_label` uses Carbon with the Owner **interface** locale (`uk` / `en` / `ru`). Chat sidebar timestamps use `Intl` with the same BCP-47 tag. Task/reminder “Сегодня” helpers in `HumanMoment` remain a known limitation.

---

## Telegram behavior

Locale switch is the stored Owner preference. Mini App boot uses the same shared props. Telegram theme does not select locale. `language_code` from initData is not written to the profile. Pairing, webhook, bot token, and Menu Button were not changed.

This commit also keeps Mini App `initData` coalescing (form-urlencoded `&` split) and routes `ProcessTelegramUpdate` to the `default` queue so the existing worker can process Telegram Chat without a systemd change.

---

## Tests

| Test | Coverage |
| --- | --- |
| `tests/Unit/Enums/OwnerLocaleTest.php` | default `uk`; supported `uk/en/ru`; unsupported → `uk` |
| `tests/Feature/OwnerLocalePreferenceTest.php` | save/persist UI and assistant locales; Inertia shared props; WebApp same locale; invalid → `uk`; `/register` 404; null stays null on read |
| `tests/Unit/Assistant/AssistantLocalePromptTest.php` | prompt includes preferred language; UI ≠ assistant; no mutation |
| `tests/Unit/Workspace/TodayBriefLocaleTest.php` | Today date/summary follow interface locale |
| `tests/Unit/Support/OwnerCopyTest.php` | locale copy + missing-key log |
| existing WebApp / login / single-user tests | Mini App auth and `/register` 404 still hold |

---

## Status table

| Item | Status |
| --- | --- |
| `uk` default and fallback | IMPLEMENTED |
| `en` / `ru` supported | IMPLEMENTED |
| Owner UI language switch | IMPLEMENTED |
| Separate assistant language | IMPLEMENTED |
| WebApp and Web share locale | IMPLEMENTED |
| UI locale ≠ assistant locale | IMPLEMENTED |
| Source data stays original | IMPLEMENTED (prompt + no write-time translation) |
| Conversation Engine preferred language | IMPLEMENTED |
| Automated tests listed above | IMPLEMENTED |
| `GET /telegram/webapp` HTTP 200 | MANUAL PASS (curl) |
| Guest `/lavr` → login; `/register` 404 | MANUAL PASS (curl / tests) |
| Vite production build | MANUAL PASS (this slice) |
| Locale switch in a real browser | NOT VALIDATED |
| Locale switch inside Telegram Mini App | NOT VALIDATED |
| Live assistant reply language with mixed UI/assistant locales | NOT VALIDATED |
| Real Telegram Chat inbound after queue routing | NOT VALIDATED |
| Menu Button / pairing / webhook live | NOT VALIDATED (unchanged; still blocked on empty bot settings) |

---

## Known limitations

- Admin / technical UI remains mixed English.
- `HumanMoment` day labels are still Russian.
- Login language dropdown is preview-only until the Owner is authenticated.
- Guest locale is always `uk`; there is no cookie-only browser locale.
- Missing Ukrainian keys return the key string and a warning log.

---

## Next recommended phase

**Phase 4** — People / Organizations / Projects as business contexts. Do not restart Phase 3C. Do not start Zoom or Executive Brief in the same slice.
