# Multi-source business integration

Canonical source ≠ business context. Product map: [DATA_SOURCES.md](DATA_SOURCES.md). People: [PEOPLE_AND_RELATIONSHIPS.md](PEOPLE_AND_RELATIONSHIPS.md). Commitments: [COMMITMENTS.md](COMMITMENTS.md). Report: [Development/LAVR_PHASE_10_REPORT.md](Development/LAVR_PHASE_10_REPORT.md).

LAVR reads Gmail, Calendar, Telegram groups, Zoom, and future APIs. It does **not** become Gmail, Telegram, or a CRM.

## CURRENT (Phase 10)

| Piece | Status |
| --- | --- |
| Multiple Google `integration_accounts` per Owner | **IMPLEMENTED** |
| Human `display_label` (Chicago, Finance, CEO) | **IMPLEMENTED** |
| Per-account OAuth tokens, encrypted at rest | **IMPLEMENTED** |
| `project_source_bindings` used for mailbox / calendar / Telegram group | **IMPLEMENTED** |
| Binding kinds `explicit` / `suggested` (AI never overwrites explicit) | **IMPLEMENTED** |
| Lightweight `source_items` (ids + metadata, not a mailbox mirror) | **IMPLEMENTED** |
| Cross-account Gmail search (`search_email`) | **IMPLEMENTED** |
| Multi-account Calendar aggregate + invitation dedupe | **IMPLEMENTED** |
| Telegram group ↔ Project binding + identity resolve | **IMPLEMENTED** |
| Commitment candidates from email / Telegram (`detected`, not `open`) | **IMPLEMENTED** |
| Completion evidence → `likely_done` (never auto-`confirmed`) | **IMPLEMENTED** |
| Cross-source correlation (one operational story) | **IMPLEMENTED** |
| Health / freshness; UNKNOWN ≠ EMPTY | **IMPLEMENTED** |
| Source delete: credentials + bindings + source_items; canonical facts kept | **IMPLEMENTED** |
| `BusinessSourceConnector` + mock external connector | **IMPLEMENTED** |
| Settings / Admin / Project Sources UI | **IMPLEMENTED** |
| **REAL MULTI-ACCOUNT LIVE VALIDATION** | **NOT VALIDATED** |

## Routing priority (Project)

1. Explicit source binding  
2. Existing thread / chat binding  
3. Canonical Person / Organization relation  
4. Strong deterministic metadata (name match)  
5. AI / suggested binding (never silent)  
6. Unresolved  

Do not guess Project on weak confidence.

## Google accounts

Each connection is one `integration_accounts` row (`provider=google`, unique `(user_id, provider, external_account_id)`). Connecting a second Google subject **does not** deactivate the first. Same Google `sub` upserts. Jobs and tools take `integration_account_id` / `account_id`. Token refresh is per-row (row lock). Failure of one mailbox does not stop the others.

Send/write still requires confirmation. Multi-account does **not** let AI send from any mailbox.

## Telegram groups

A group is a source after the bot actually receives messages. Adding the bot is **not** sufficient.

Requirements (Owner / BotFather; LAVR does not change them):

- Bot membership in the group  
- Privacy mode: Group Privacy **OFF** (`/setprivacy` → Disable) so the bot receives ordinary messages, not only commands/mentions  
- Admin / read rights if the current Telegram client requires them  
- Webhook delivery to this host (do not retarget the webhook in Phase 10)

Health: `telegram_groups.status`, `last_message_at`, `settings.monitoring_enabled`. Explicit Project bind enables monitoring.

Private Owner↔LAVR chat is **not** treated as employee commitments.

## Identities

Email / `telegram_user_id` / username → `person_identities`. Known Person is linked. Unknown: unresolved identity on the `source_item`. **No silent Person create.**

## Commitments

High-confidence work promises from bound mail/groups → `CommitmentCandidate` → `detected`. Fingerprints are idempotent. Completion phrases (`готово`, `отправил`, `attached`, `финальн`) add delivery evidence and may set `likely_done`. Weak progress (`занимаюсь`, `почти готово`, `almost ready`) is progress evidence only.

## Correlation

Same Person + Project + close topic / thread / commitment id → one first-class Commitment. Telegram “almost ready” is progress; later email “final attached” is delivery. AI suggestion does not merge operational objects at low confidence.

## Provenance

Every `source_items` row stores `user_id`, `integration_account_id` (when Google), `source_type`, `source_instance`, `external_id`, timestamps, optional person/project, snippet/subject, `content_hash`. That is the Phase 12 cleanup index. Do not build a giant event lake.

## Source lifecycle

Health: `healthy` / `degraded` / `blocked` / `disabled`. Freshness: `last_success_at`, `last_event_at`, `last_processed_at`. Collectors isolate errors. UNKNOWN (source down) is not EMPTY (healthy query, no rows).

## Deletion vs canonical

Remove Google/Telegram source: revoke credentials where applicable, disable processing, delete `source_items` and bindings for that instance. Confirmed People / Projects / Commitments stay. Evidence pointers may become unavailable.

## External connectors

`BusinessSourceConnector`: `health`, `collect`, `normalize`, `resolveIdentities`, `sourceReference`. Reference: `MockExternalConnector`. Bitrix24 / dashboards / custom REST remain later via APIs, not browser scraping. Phase 11 consumes source health and `source_items` for operational events; it does not add new connectors.

## TEST DATA PROVENANCE / HANDOVER

Phase 12 **IMPLEMENTED** guarded handover cleanup (`lavr:handover-cleanup`, dry-run by default). Live handover against developer accounts: **NOT VALIDATED**. See [HANDOVER_CLEANUP.md](HANDOVER_CLEANUP.md).

| Record | Bound to integration? | Purge on source delete now | Phase 12 |
| --- | --- | --- | --- |
| `integration_accounts` + encrypted tokens | yes | yes (disconnect) | hard-delete remaining rows for test accounts |
| `project_source_bindings` for that source id | yes | yes | remaining suggested rows |
| `source_items` | yes (`integration_account_id` / `source_instance`) | yes | any leftover cache |
| Watcher / report `source_config` pointing at account id | yes | processing stops when account disabled | rewrite or drop test watchers/reports |
| `tool_execution_logs` (no secrets) | account id nullable | keep | optional purge by account |
| Telegram group rows + messages | group instance | monitoring off; items deleted | decide: keep group transcript vs purge test chats |
| Knowledge / Memory created only from that source | if source ref exists | **not** auto-deleted | explicit decision |
| People / Organizations / Projects | canonical | **keep** | keep unless created only for the test campaign |
| Commitments `detected` never confirmed | mixed | keep | Owner decision: dismiss vs keep |
| Commitments `open` / `confirmed` | canonical | **keep** | keep |
| Meetings / Zoom artifacts | Zoom/meeting source | Zoom webhook unchanged | separate Zoom test data policy |
| `operational_events` / `proactive_proposals` | source_type / source_id / evidence_pointer | keep (facts still tracked) | purge rows derived from test integrations; keep confirmed canonical entities |

Do not copy JARVIS tokens or databases. Do not connect developer personal accounts from this phase.

## Future live validation (do not run in Phase 10)

1. Google account 1 (CEO) + account 2 (show mailbox)  
2. Calendar on both; shared invitation appears once  
3. Telegram group with privacy off + monitoring + Project bind  
4. Zoom transcript → Meeting (existing Phase 5B)  
5. Cross-source Commitment (meeting promise → Telegram progress → email delivery)  
6. Executive Brief with one mailbox blocked  
7. Leadership Review (no email-volume metrics)  
8. Watcher scoped to one mailbox vs all  
9. Scheduled report source set (account / project)

**REAL MULTI-ACCOUNT LIVE VALIDATION: NOT VALIDATED**
