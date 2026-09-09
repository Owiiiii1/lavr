> **Documentation status:** HISTORICAL engineering diary (JARVIS/LAVR incident work). Not architecture. Canonical: [CURRENT_STATE.md](../CURRENT_STATE.md), [AUTOMATION_ENGINE.md](../AUTOMATION_ENGINE.md).

# Digest phrasing never landed: reasoning tokens ate the budget (2026-09-09)

## Starting HEAD

Uncommitted report-quality work on `main` after `682b8e1`. No dependency changes.

## Owner report

Test digest arrived as a sender-subject list padded with Italian email text and zero-width characters: «тут ничего непонятно, по сути опять перечисление, плюс суть мне нужно на русском».

## Root cause

`ai_used=false` on every phrasing attempt. `scheduled_report.phrasing_skipped reason=empty` hid the real reason: the Owner role runs `gemini-3.7-flash`, and the phrasing call capped `max_tokens` at 500. A live probe returned `finishReason=MAX_TOKENS` with 20 visible output tokens out of 544 total — hidden thinking consumed the budget, so the completeness guard correctly dropped a stub and the deterministic list shipped instead. The same 400-token cap starved daily/tomorrow briefs.

## Change

- `productivity.briefs.phrasing_max_tokens` (1600) sizes every phrasing call; a live probe at 1200+ returns `STOP` with a full Russian digest.
- Discarded phrasing logs `productivity.phrasing_rejected` with the provider finish reason, so a starved budget is no longer indistinguishable from an empty answer.
- Digest prompt demands a Russian retelling of foreign subjects and snippets, merges letters from one sender, and forbids sender-subject lists.
- Deterministic fallback no longer quotes snippets (untranslatable without the model) and clips subjects; `MailTextNormalizer` strips zero-width padding and collapses whitespace before compose and before the model sees it.
- `mailBucket` noise pattern now catches `do-not-reply` variants.

## Verification

`php artisan test --compact` on the normalizer, composer, phrasing and scheduled-report suites: 22 passed. Two pre-existing failures in `ScheduledReportsTest` (`test_reminder_and_gmail_event_wording_stay_on_their_tools`, `test_workspace_reports_index_lists_owned_cards`) fail identically with the change stashed. Owner-approved live test send over a last-24h window returned `ai_used=true` and a spoken Russian digest; the real 09:00 slot was not re-run.

---

# Mail digest as spoken summary (2026-09-09)

## Starting HEAD

Uncommitted report-quality work on `main` after `682b8e1` (`feat: add scheduled composite reports`). No dependency changes.

## Owner request

09:00 mail+groups report arrived as a sender-subject list. Owner asked whether to rephrase the bot or fix the mechanism. Answer: mechanism. Chat already created `mail_groups_digest`.

## Change

- Deterministic digest is prose: count, important, other, noise-as-count, groups as a sentence.
- Collector keeps a bounded Gmail `snippet` for compose; dispatch still unsets `body` / `snippet` / `text` before persist.
- `mail_groups_digest` AI prompt writes a spoken summary; must not dump a list or invent facts. Short complete summaries are accepted. Truncated AI falls back to the prose digest.
- Phrasing skip/fail logs reason codes only.

Do not re-send today’s 09:00 slot. Next digest is 2026-09-10 09:00 Europe/Rome. Cursor did not call live Gmail or mutate Owner rows.

## Tests authored but NOT executed

Mail digest prose (not bullets), noise not listed, display names not raw noreply addresses, snippet in important fact, short spoken AI accepted, truncated AI falls back to prose. PHPUnit / `php artisan test` / Pest were **not** run.

---

# Morning report truncation + calendar DI (2026-09-09)

## Starting HEAD

`92b3170` (`feat: add telegram tts speed setting`). Branch `main`. No dependency changes. Not committed unless Owner asks.

## Live production failure

Owner 08:30 Europe/Rome report **#2** (`daily_plan`) delivered title `Утренний отчёт: планы на сегодня` and body `Доброе утро. Сводка на сегодня,` (31 chars). Run #1 status `partial`, `source_errors`: `Календарь сейчас недоступен.` Collector had two WOW Cleaning tasks; they never reached Telegram because AI rewrite replaced the deterministic brief.

## Calendar was not disconnected

Owner Google account **#479** (`owlnightmail@gmail.com`) is `connected` with `https://www.googleapis.com/auth/calendar` and Gmail scopes. Laravel 13 `Container::resolveClass()` returns a constructor default when the class is not bound. `ScheduledReportCollector` defaults `$calendar`, `$gmail`, `$accounts` to `null`, so production never called Google. Tests that `new ScheduledReportCollector` still simulate partial calendar failure.

## Fixes

- Bind `ScheduledReportCollector` (and `ProductivityBriefCollector` + synthesis) in `AppServiceProvider` with `$app->make(...)`.
- Reject truncated/incomplete AI phrasing (`ProductivityBriefPhrasing`); keep the deterministic report. Same guard on briefs.
- Log `scheduled_report.source_unavailable` with a reason code. No tokens, no event/mail bodies.

Today’s 08:30 slot is consumed. Cursor did not re-send it, did not dispatch Owner reports, and did not call live Google.

## Tests authored but NOT executed

Truncated AI falls back (composer, brief, dispatch with task titles). Complete AI still used. Empty AI falls back. Container collector receives Calendar/Gmail/accounts. Existing bare-collector partial-failure test remains. PHPUnit / `php artisan test` / Pest were **not** run.

---

# Scheduled Reports

## Starting HEAD

`92b3170` (`feat: add telegram tts speed setting`).

Branch `main`. No dependency changes.

## Live production failure

Owner asked Jarvis (Основной, 2026-09-08) for three clock-time reports:

- 22:00 tomorrow plans from tasks + own calendar + linked Family calendar
- 08:30 plans for today
- 09:00 new mail + groups summary

Jarvis claimed success. Production objects were watchers, not reports: **#193** calendar-change watcher (primary only, accidental `in:inbox`, mail-oriented copy), **#194** Gmail digest, **#191** existing Gmail digest with no Telegram Groups source. Cursor did not mutate those Owner rows.

## Root architectural issue

A scheduled multi-source report is not a Reminder and not a Watcher. Watchers wait for a condition or source event. Reports fire at a known local time, collect configured sources, synthesize one grounded message, and deliver it.

## Reminder vs Watcher vs Scheduled Report

- Reminder: the user acts at a known time (“напомни в 9 проверить почту”).
- Watcher: future condition / source event (“жди письмо от школы”, “следи за задачей”).
- Scheduled Report: at a known time collect sources and send one report (“каждый вечер в 22 планы на завтра”).

## Existing Productivity Brief reuse

Collector / renderer / AI synthesizer / dispatch from `app/Services/Productivity/*` are reused for deterministic collection + optional phrasing. Named Scheduled Reports are the chat-created front-end. Generic B.2 morning/evening briefs stay opt-in and **off by default**. Matching brief modes are skipped when an active `daily_plan` / `tomorrow_plan` report exists so the engines do not double-send.

## Scheduled report model

Tables `scheduled_reports` and `scheduled_report_runs` (unique `scheduled_report_id` + `slot_key`). Status active/paused/cancelled. Timezone-aware `daily_local`. Owner timezone Europe/Rome.

## Report types

`daily_plan`, `tomorrow_plan`, `mail_groups_digest`, `custom_composite`.

## Source adapters

Semantic sources: tasks, reminders, projects, synthesis, google_calendar, gmail, telegram_groups, notifications. One report may combine several. Model never composes raw watcher configs.

## Calendar + shared calendar handling

`calendar_scope=all_relevant` includes primary, selected, and summaries matching Семья/family. IDs internally, names in UI. Read-only. Partial failure does not cancel the report.

## Gmail source

Read-only query at execution. Period `since_previous_report` (first run last 24h). No mark-read, archive, label, reply. Bodies are not stored in run metadata.

## Telegram Groups source

Summarizes stored `messages` for the user’s groups in the same period. Does not invent live Telegram history. Does not expose internal group ids.

## Task/plan source

Tasks due in the period, overdue important open work, reminders, grounded synthesis. 22:00 focuses on tomorrow; 08:30 on today.

## Period semantics

Execution time ≠ observation window. `today` / `tomorrow` / `since_previous_report` / `last_24h`.

## Scheduler/idempotency

`jarvis:reports:dispatch` every 5 minutes, `withoutOverlapping(4)`. Due when `next_run_at <= now`. Unique slot key prevents double-send. Not exact-second delivery.

## Partial recovery

One failed source still delivers. Copy notes the unavailable source in natural language.

## Delivery

Notification Center (`scheduled_report_ready`) + existing Web Push. Telegram via `SendsReminderTelegram`. Default telegram enabled. No new transport.

## NL routing

Periodic plan/mail/group reports → `create_scheduled_report`. Self-reminder wording stays Reminder. Gmail event wording stays Watcher. Old Gmail-digest-watcher hijack is retired for those phrases.

## Success grounding

Chat may say a report is configured only when `create_scheduled_report` returns `success=true` and `report_id`. Failed creation cannot claim success. Partial multi-report requests must name which ones exist.

## Conversational updates

Follow-ups update a unique trusted recent report (`add_source`). Ambiguous morning reports ask which one. No guessed ids.

## UI

Workspace Center **Отчеты** distinct from Напоминания and Автоматизации. Cards show name, “Каждый день · 22:00”, source labels. Pause / resume / cancel. Create through chat.

## Existing brief consolidation

Decision: Scheduled Reports are canonical named reports. B.2 briefs remain opt-in unnamed fallback and are skipped when an overlapping named report is active.

## Broken Owner data remediation

Do **not** patch production rows. After deploy Owner should cancel:

- Watcher **#193** (not a 22:00 tomorrow plan)
- Watcher **#194** (not an 08:30 today plan)
- Watcher **#191** if it would overlap the 09:00 mail+groups report

Then recreate via chat (validation scenarios A/B/C).

## Files changed

New report domain (models, migration, collector, composer, dispatch, tools, controller, command, Reports panel, tests). Wired into capabilities, tool registry, prompts, watcher/reminder hijack retirement, workspace chrome, scheduler, Productivity Brief skip, docs.

## Migration

`2026_09_08_195448_create_scheduled_reports_tables` — additive. `php artisan migrate --force`. No rollback. No `migrate:fresh`.

## Tests authored but NOT executed

Intent routing, period/sources/shared calendar, 08:30/09:00, since-previous vs first-run 24h, first-run before/after slot, partial send, duplicate slot, failed create, reminder/event wording, follow-up update, ambiguous clarification. Recurring Gmail digest hijack tests retargeted to Scheduled Reports. PHPUnit / `php artisan test` / Pest were **not** run.

## Static checks

`php -l` on touched PHP, `vendor/bin/pint --dirty --format agent`, `composer validate`, `npm run build`, `git diff --check`, `php artisan route:list`, `php artisan schedule:list`, additive migrate. No live report dispatch. No live Gmail/Calendar/Telegram API calls.

## Owner validation

After deploy, cancel obsolete watchers, then:

A) 22:00 Europe/Rome tomorrow plan, tasks + primary + Family
B) 08:30 today plan, tasks + calendars
C) 09:00 Gmail + Telegram groups

Inspect three Report cards. No Gmail/Calendar watcher pretending to be those reports. Wait for the slot or an Owner-authorized preview later. Cursor did not run a live report.

## Live chat break after deploy (Основной)

Gemini rejected the whole tool list before any call:

`GenerateContentRequest.tools[0].function_declarations[45].parameters.properties[sources].items: missing field.`

`create_scheduled_report` / `update_scheduled_report` declared `sources` as ARRAY without `items`. Chat in Основной returned «Сейчас не удалось сформировать ответ» for every turn. Fixed by adding Gemini `items` / OBJECT `properties`. No Owner reports created by Cursor. Retry the 22:00 request in chat after deploy.

## Production safety

No phpunit. No Owner row writes. No proactive sends. No live provider calls. No migrate:fresh / rollback.
