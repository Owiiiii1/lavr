# Production validation plan

Canonical live-validation backlog. Older [DEFERRED_VALIDATION.md](DEFERRED_VALIDATION.md) points here.

Do **not** run this campaign in Phase 12. Do not connect personal developer accounts from this document.

## Distinction

| Phrase | Meaning |
| --- | --- |
| IMPLEMENTED | Code exists |
| MANUAL PASS | Owner confirmed in production |
| NOT VALIDATED | Campaign not run |

**CODEBASE READY FOR LIVE VALIDATION** ≠ client acceptance.

## Outstanding live validations

- Telegram Mini App E2E
- Telegram voice input
- Telegram groups
- Google multi-account
- Calendar
- Zoom live
- Commitments live extraction
- Automation scenarios A–E
- Executive Brief live
- Leadership Review live
- Proactive campaign
- Cross-source correlation live
- External send confirmation
- Handover cleanup against developer accounts

## Stages (do not run yet)

**A — Infrastructure:** login, workspace, Mini App, queue, scheduler, AI, notifications.

**B — Single integration:** Gmail, Calendar, Telegram group, Zoom — one at a time.

**C — Multi-source:** meeting promise → Telegram progress → email delivery → one Commitment story.

**D — Automation:** reminder, watcher, scheduled report, Executive Brief, Leadership schedule, operational scan.

**E — Executive intelligence:** “What needs my attention?”, person/project/meeting/overdue/delta questions.

**F — Proactivity:** overdue → proposal → snooze → progress → delivery → confirm. No silent third-party send.

**G — Handover:** developer integrations, cleanup dry-run, preservation plan, execute only when explicitly decided.

Record each run with [Development/LIVE_VALIDATION_REPORT_TEMPLATE.md](Development/LIVE_VALIDATION_REPORT_TEMPLATE.md). Do not change expectations to make a bug pass.
