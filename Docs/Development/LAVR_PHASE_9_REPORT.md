# LAVR Phase 9 report — Leadership Review

**Date:** 2026-09-14  
**Repo:** `Owiiiii1/lavr` (`main`)  
**Host:** `/var/www/lavr`  
Status vocabulary: [CURRENT_STATE.md](../CURRENT_STATE.md).

## Status

| Item | Status |
| --- | --- |
| First-class `leadership_reviews` | **IMPLEMENTED** |
| Review types: owner, project, meeting, team, person | **IMPLEMENTED** |
| Periods 7 / 30 / custom (default 30) | **IMPLEMENTED** |
| Deterministic metrics + ratios | **IMPLEMENTED** |
| Findings grounded; observation ≠ judgment | **IMPLEMENTED** |
| Evidence refs on significant findings | **IMPLEMENTED** |
| No overall leadership score / personality scoring | **IMPLEMENTED** |
| Strengths + attention areas | **IMPLEMENTED** |
| Trend vs previous equal period + min sample guard | **IMPLEMENTED** |
| AI optional + deterministic fallback | **IMPLEMENTED** |
| Wording safety guard (uk/en/ru) | **IMPLEMENTED** |
| Workspace `/lavr/leadership` | **IMPLEMENTED** |
| Project / Person / Meeting integration | **IMPLEMENTED** |
| Executive Brief actionable `leadership_signal` only | **IMPLEMENTED** |
| Weekly schedule + Telegram compact | **IMPLEMENTED** |
| Localization uk/en/ru | **IMPLEMENTED** |
| Automated tests | **IMPLEMENTED** |
| Owner live synthetic dataset (4 meetings / 12 commitments) | **NOT VALIDATED** |
| First-class Decisions table | **NOT** (out of Phase 9) |

## Schema

`leadership_reviews`: `user_id`, `review_type`, `period_start` / `period_end`, nullable `project_id` / `person_id` / `meeting_id`, `status`, `summary`, `metrics_json`, `findings_json`, `source_snapshot_json` (no bodies), `generated_at`, `generated_by` (`deterministic` / `ai_assisted`), `origin`, unique `(user_id, run_key)`.

`user_productivity_settings`: `leadership_review_enabled` (default true), weekday (ISO 1=Mon, default 1), local time `09:00`, Telegram / in-app, `last_leadership_review_at`.

`AutomationType::LeadershipReview`. Notification `leadership_review_ready`.

## Review types / scopes

- `owner` / `team` — overall process (Owner leadership + team/process).
- `project` — project-scoped meetings + commitments.
- `person` — operational patterns around one Person (counts, overdue, follow-up, workload). Not a performance score.
- `meeting` — that meeting’s action/decision quality.

## Metrics

Deterministic from Meetings (analysis JSON without transcript excerpts) and Commitments. Ratios allowed. No `leadership_score`.

## Findings

Categories: clarity, ownership, deadlines, follow_up, decision_followthrough, commitment_reliability, meeting_effectiveness, bottlenecks, workload_concentration, owner_dependency.

Severity `critical` / `high` / `medium` / `low` from thresholds (example: overdue without follow-up, owner coverage 0% with ≥5 actions). Confidence `high` / `medium` / `low`; small sample → low.

High/critical findings carry `evidence_refs` (`commitment` / `meeting` / `project` / `automation_run`) with Workspace hrefs.

## Trend / sample

Previous period = equal length immediately before current. Compare `deadline_coverage_pct` and `owner_coverage_pct`. If meetings+commitments sample `< 3` in either period: «Недостатньо даних для тренду.»

## AI role / wording safety

AI may compact wording of an already computed summary. Reject personality language (`lazy`, `toxic`, `narcissistic`, uk/ru equivalents), invented numbers, unknown persons, locale mismatch, raw JSON. Fallback deterministic.

## UI

`/lavr/leadership` — Overview, Strengths, Attention, Meetings, Commitments, Delegation, Follow-up, Bottlenecks. Finding click → source refs.

Person: Operational patterns. Project: Leadership / Process. Meeting: Meeting quality.

Admin `/leadership-reviews`: list, status, scope, metric counts, snapshot, regenerate (new row).

## Integrations

Executive Brief collector adds at most one `leadership_signal` when a recent review has a high/critical actionable finding, or three consecutive same-project meetings lack owners. Not daily coaching noise.

## Telegram

Compact: heading, 3 attention bullets, strengths, `[Open in LAVR]`. Deep link `leadership_{id}` / `/lavr/leadership`.

Scheduled: `leadership-reviews:dispatch` every minute; due-check uses Owner weekday + local time. Key `leadership_review:{user_id}:weekly:{period_start}`.

## Tests

Feature: metrics fixture, findings + evidence, sample/trend, AI personality/hallucination fallback, Telegram compact, workspace generate/drill-down/person/project/meeting/admin auth, weekly idempotency + manual history, deterministic persist.

Unit: wording guard; Telegram `leadership_{id}`.

Regression: Executive Brief, Commitments, Meetings, Automation, People/Projects, Telegram WebApp, locales, `/register` 404.

## Manual validation

Synthetic dataset (4 meetings, 12 commitments, 3 overdue, 4 without deadline, 2 unresolved owners, repeated topic, likely_done unconfirmed, concentration, previous worse deadline coverage) is **NOT VALIDATED** on the Owner live account.

Expected if run: attention on deadline clarity, ownership gaps, overdue/follow-up; strength if deadline coverage improved vs previous; no personality language.

## Production deploy

`/usr/bin/php8.5 artisan migrate --force`; `npm run build`; `queue:restart` in `/var/www/lavr` only. No nginx, Telegram webhook, or Zoom webhook changes.

## Known limitations / Phase 10

No first-class Decisions. No email/Telegram private-message analysis. No employee ranking. No capacity-based “overloaded” claims. Owner live pass outstanding. Phase 10 is multi-source business integration — do not start it from this slice.
