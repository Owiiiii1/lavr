# LAVR Phase 11 report — Proactive Operational Control

**Date:** 2026-09-14  
**Host:** `/var/www/lavr`  
**Repo:** `Owiiiii1/lavr` `main`  
**Artisan:** `/usr/bin/php8.5 artisan`

Phase 11 adds the Owner-facing control loop on top of Phases 1–10. It does **not** replace B.2 proactive heuristics, CommitmentNotifier, Executive Brief, or ExternalActionPolicy.

## LIVE PROACTIVE CAMPAIGN: NOT VALIDATED

No real Owner campaign, no developer personal accounts, no autonomous third-party sends.

## Status legend used below

| Mark | Meaning |
| --- | --- |
| IMPLEMENTED | In production code on this host |
| MANUAL PASS | Feature tests / artisan checks on this host |
| NOT VALIDATED | Owner live campaign not run |

## Delivered

| Area | Status |
| --- | --- |
| Event model (`operational_events`) | IMPLEMENTED |
| Proposal model (`proactive_proposals` + audits) | IMPLEMENTED |
| Typed rule registry | IMPLEMENTED |
| Assessment (importance / urgency / actionability / notify) | IMPLEMENTED |
| Severity + overdue escalation 6h / 24h / 72h | IMPLEMENTED |
| ExternalActionPolicy `read` / `suggest` / `draft` / `execute` | IMPLEMENTED (third-party execute default **off**) |
| Quiet hours / daily cap / cooldown | IMPLEMENTED |
| Stale revalidation before execute | IMPLEMENTED |
| Auto-resolution on state change | IMPLEMENTED |
| UI `/lavr/proactive` + Today/Brief/Person/Project/Commitment | IMPLEMENTED |
| Telegram Owner compact alert + deep link | IMPLEMENTED |
| Audit (no message bodies) | IMPLEMENTED |
| Tests (events, proposals, policies, noise, UI, Telegram) | MANUAL PASS |
| Live campaign | **NOT VALIDATED** |
| Handover purge | documented only; Phase 12 |

## Pipeline

OBSERVE → CORRELATE → ASSESS → PROPOSE → APPROVE/POLICY → EXECUTE optional → VERIFY → RECORD

## Scheduler / hooks

- `operational-control:scan` every 10 minutes (`withoutOverlapping`)
- Hooks: commitment status change, meeting analysis completed, source item processed, integration `markError`, automation failure
- Existing `jarvis:proactive:dispatch` kept

## Safety

- Unresolved or ambiguous Person identity → send blocked
- Stale “remind overdue” after confirm → no send, proposal expired
- Draft ≠ sent
- Telegram groups are not auto-written
- Gmail send reuses existing confirmation tools; Phase 11 does not add a second sender
- Global `operational_alerts_enabled` still tracks facts when alerts are off

## Handover implications

Events/proposals store source pointers (`source_type`, `source_id`, `source_external_id`, `evidence_pointer`). Phase 12 can delete integration-bound derived rows. Confirmed canonical People / Projects / Commitments remain.

## Phase 12 readiness

Ready to polish: live developer-account campaign, optional Decisions, purge of test-integration derived events/proposals. Do not start Phase 12 purge from this report.

## Tests

`tests/Feature/OperationalControlTest.php` and `OperationalControlWorkspaceTest.php` plus regression subset (commitments, brief, leadership, multi-source, meetings, `/register` 404).

## Production

Additive migrations `operational_events`, `proactive_proposals`, `proactive_proposal_audits`, productivity settings columns. No nginx / Telegram webhook / Zoom webhook changes.
