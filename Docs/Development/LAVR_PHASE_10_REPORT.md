# LAVR Phase 10 report — Multi-source business integrations

**Date:** 2026-09-14  
**Repo:** `Owiiiii1/lavr` (`main`)  
**Host:** `/var/www/lavr`  
Status vocabulary: [CURRENT_STATE.md](../CURRENT_STATE.md). Architecture: [MULTI_SOURCE_INTEGRATION.md](../MULTI_SOURCE_INTEGRATION.md).

## Status

| Item | Status |
| --- | --- |
| Multi-account Google model (evolve `IntegrationAccount`) | **IMPLEMENTED** |
| Additive migration (`display_label`, `enabled`, `health`, freshness, `source_items`, `binding_kind`) | **IMPLEMENTED** |
| OAuth isolation (no global active-account deactivation) | **IMPLEMENTED** |
| Project source bindings operational | **IMPLEMENTED** |
| Gmail collection/search scoped account / project / all | **IMPLEMENTED** |
| Calendar multi-account aggregate + invitation dedupe | **IMPLEMENTED** |
| Telegram groups bind + identities + summaries | **IMPLEMENTED** |
| Identity resolution (no Person auto-create) | **IMPLEMENTED** |
| Commitment candidates from email/Telegram (`detected`) | **IMPLEMENTED** |
| Completion evidence → `likely_done` | **IMPLEMENTED** |
| Cross-source correlation (one Commitment) | **IMPLEMENTED** |
| Provenance on `source_items` | **IMPLEMENTED** |
| Health / freshness; UNKNOWN ≠ EMPTY | **IMPLEMENTED** |
| Error isolation | **IMPLEMENTED** |
| Source deletion semantics (source vs canonical) | **IMPLEMENTED** |
| Handover cleanup readiness (graph only; no Phase 12 purge) | **IMPLEMENTED** |
| Executive Brief multi-source | **IMPLEMENTED** |
| Automation / report source scopes | **IMPLEMENTED** |
| Integration UI (Settings + Admin + Project Sources) | **IMPLEMENTED** |
| Localization uk / en / ru | **IMPLEMENTED** |
| Automated tests (mocks/fixtures) | **IMPLEMENTED** |
| **REAL MULTI-ACCOUNT LIVE VALIDATION** | **NOT VALIDATED** |
| Bitrix / real external APIs | **NOT** (adapter + mock only) |
| Phase 11 proactive autonomy | **NOT** |
| Phase 12 destructive handover cleanup | **NOT** |

Manual Owner confirmation of live multi-mailbox/group flows: **NOT VALIDATED**. Code validation used HTTP fakes and fixtures only. Developer personal accounts were **not** connected.

## Multi-account model

Existing `integration_accounts` is the Google (and GitHub/Zoom) connection. Columns added: `display_label`, `enabled`, `health` (`healthy` / `degraded` / `blocked` / `disabled`), `last_error_message`, `last_event_at`, `last_processed_at`. Unique `(user_id, provider, external_account_id)` already allowed many Google rows; runtime no longer calls `deactivateOtherAccounts` for Google. Additional connect uses `prompt=select_account consent`.

`getActiveAccount()` remains a **fallback** (latest enabled connected) for single-account write tools and GitHub. Core Gmail/Calendar read paths iterate enabled accounts or accept `account_id` / `project_id`.

## Migration

`2026_09_14_090434_add_multi_source_integration_columns` — additive. Existing connected Google rows backfilled to `enabled` + `healthy`. No JARVIS DB/token copy.

## OAuth isolation

Each account has its own encrypted credential envelope. Refresh locks the row by id. One 401 marks that account blocked/degraded and continues siblings. Cache/lock key is the account id.

## Project source bindings

`project_source_bindings` now used for `google_mailbox`, `google_calendar`, `telegram_group` (plus zoom / external_api reserved). `binding_kind`: `explicit` | `suggested`. Suggested never overwrites explicit. One Project may have many sources; one source may be shared if configured. No exclusive ownership.

## Gmail

Live/on-demand search. No mailbox mirror. `search_email` searches all enabled mailboxes unless `account_id` or Project bindings apply. Results include account label, sender, date, project if resolved. Ingest writes lightweight `source_items` and may extract commitments.

## Calendar

`list_calendar_events` / `search_calendar_events` aggregate enabled calendars. Shared invitations dedupe on `iCalUID` (else title+start+organizer). Source label is available on the event payload; ordinary UI is not a calendar clone.

## Telegram groups

Inbound persist unchanged. After persist, `SourceIngestService` runs when monitoring is enabled or the group is bound. Identities resolve to People. Group summary tool is grounded (updates, commitments, questions) — not a dump. Privacy mode documented in [TELEGRAM_GROUPS.md](../TELEGRAM_GROUPS.md) and [MULTI_SOURCE_INTEGRATION.md](../MULTI_SOURCE_INTEGRATION.md). No webhook URL change.

## Identity / candidates / completion

Sender email and Telegram ids → `PersonIdentity`. Unknown → unresolved on the source item. High-confidence promises → `detected`. Strong completion → `likely_done` + delivery evidence. Weak phrases stay progress. Owner personal commands are skipped.

## Correlation / provenance

`SourceCorrelationService` matches Person + Project + topic tokens before creating a second commitment. `source_items` always store source type, instance, external id, timestamps, optional person/project.

## Health / errors / queues

Per-account try/catch in collectors, Brief, reports, Gmail watchers. Existing queues reused; no new queue names. Google/Telegram HTTP already uses bounded retries in adapters.

## Source deletion

`SourceDeletionService`: revoke Google, disable Telegram monitoring, delete bindings + `source_items`. Canonical People/Projects/Commitments remain. Phase 12 will add a purge tool using this provenance graph.

## UI / tools

Settings → Integrations: Google account cards (label, email, Gmail/Calendar, health, test, disable, reconnect, remove). Telegram groups list. Project detail Sources attach/detach. Tools: `list_integrations`, `list_project_sources`, `search_email`, bind/unbind/rename/disable/remove, `summarize_telegram_group`. `get_person_status` / `get_project_status` include recent mailbox/Telegram facts. Destructive remove always confirms.

## Tests

Feature: `MultiSourceGoogleTest`, `MultiSourceCommunicationsTest`, `MultiSourceToolsTest` plus existing Google/Calendar/Gmail/Telegram/Brief/Watchers/Commitments regression. Fixtures and `Http::fake` only.

## Live validation

**REAL MULTI-ACCOUNT LIVE VALIDATION: NOT VALIDATED**

Checklist for a later campaign: [MULTI_SOURCE_INTEGRATION.md](../MULTI_SOURCE_INTEGRATION.md#future-live-validation-do-not-run-in-phase-10).

## Phase 11 readiness

Adapter interface exists (`BusinessSourceConnector`). Mock external connector only. No Bitrix, no scraping, no autonomous employee messaging, no proactive control loop.
