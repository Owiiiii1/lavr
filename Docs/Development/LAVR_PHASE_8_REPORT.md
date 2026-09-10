# LAVR Phase 8 report — Executive Brief

**Date:** 2026-09-10  
**Repo:** `Owiiiii1/lavr` (`main`)  
**Host:** `/var/www/lavr`  
Status vocabulary: [CURRENT_STATE.md](../CURRENT_STATE.md).

## Status

| Item | Status |
| --- | --- |
| First-class `executive_briefs` | **IMPLEMENTED** |
| Morning settings (time, Telegram, in-app, weekends) | **IMPLEMENTED** |
| Collectors (commitments, meetings, Gmail, Calendar, integrations, automation failures) | **IMPLEMENTED** |
| Deterministic prioritization + Attention Now | **IMPLEMENTED** |
| Dedupe + previous-brief delta | **IMPLEMENTED** |
| Optional AI + deterministic fallback | **IMPLEMENTED** |
| Partial sources | **IMPLEMENTED** |
| Today uses Executive Brief | **IMPLEMENTED** |
| `/lavr/briefs` + Admin `/executive-briefs` | **IMPLEMENTED** |
| Telegram compact delivery + `brief_{id}` deep link | **IMPLEMENTED** |
| Scheduled idempotency + manual regenerate as new row | **IMPLEMENTED** |
| Localization uk/en/ru | **IMPLEMENTED** |
| Automated tests | **IMPLEMENTED** |
| Owner live synthetic morning scenario | **NOT VALIDATED** |
| Evening/weekly product UI | **NOT** (types exist; UI not expanded) |
| Leadership Review / Decisions table / PDF | **NOT** (out of Phase 8) |

## Schema

`executive_briefs`: `brief_type`, `origin`, period, `generated_for`, timezone, locale, status, `priority_score`, `summary`, `sections_json`, `source_snapshot_json`, `automation_run_id`, `regenerated_from_id`, unique `(user_id, run_key)`.

`user_productivity_settings`: `morning_brief_enabled` (default true), `morning_brief_local_time` (08:30), Telegram/in-app/weekends, `last_morning_brief_at`. Existing `daily_brief_*` columns unchanged.

## Brief types

`morning` (Phase 8 product), `evening`, `weekly` (architectural only).

## Collectors / priority / dedupe / delta

Structured domains first. Gmail/Calendar failures → snapshot errors, status `partial`. `blocked_auth` → Attention. Confirmed/completed commitments omitted. Attention holds overdue, blocked auth, meeting risk, detected commitments (max 7). Same `dedupe_key` appears once as primary. Unchanged low/normal inbox/FYI from the previous brief is suppressed; still-overdue may repeat as “ще не вирішено”.

## AI

Phrasing only. Reject raw JSON, truncated, technical markers, unknown `source_id`. Fallback is production-quality deterministic uk/en/ru.

## Scheduling / Telegram / Today / settings

`executive-briefs:dispatch` every minute. Key `executive_brief:{user_id}:morning:{local-date}`. Weekends skipped unless enabled. Manual generate always. One Telegram message per run; retry of a finished run does not resend. Today shows the latest morning brief, not a second dump. Compact settings live on the existing productivity card.

## Tests

Feature: collectors, partial calendar, attention/dedupe/cluster, delta, AI fallback, Telegram compact/truncate, timezone/weekends/DST, scheduled idempotency, manual history, workspace/admin auth. Unit: prioritizer.

Regression covered by existing suites (scheduled reports, watchers, reminders, commitments, meetings, people, Telegram WebApp deep links, locales).

## Manual validation

Synthetic morning scenario from the Phase 8 brief (overdue, due today, likely_done, meetings, risk, mail, calendar timeout, blocked auth, yesterday completed) is **NOT VALIDATED** on the Owner live account.

## Production deploy

`/usr/bin/php8.5 artisan migrate --force`; frontend build; `queue:restart` in `/var/www/lavr` only. No nginx, Telegram webhook, or Zoom webhook changes.

## Known limitations / Phase 9

Evening/weekly UI not built. No Decisions table. No PDF. Clustering requires person-name overlap with email sender. Owner live pass outstanding. Phase 9 is Leadership Review — do not start it from this slice.
