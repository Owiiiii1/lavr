> **KEEP — live validation backlog.** Canonical campaign: [PRODUCTION_VALIDATION_PLAN.md](PRODUCTION_VALIDATION_PLAN.md). This file remains the historical Core Daily Workflow table.

# Deferred Validation Backlog

Owner postponed live validation campaigns. These items are **IMPLEMENTED / NOT VALIDATED** (or prepared, not executed) unless a row below says otherwise. They are **not** a claim that Core Daily Workflow covered them. They do **not** block further development.

Runtime snapshot: [CURRENT_STATE.md](CURRENT_STATE.md). Plan: [PRODUCTION_VALIDATION_PLAN.md](PRODUCTION_VALIDATION_PLAN.md). Core Daily Workflow runbook: [VALIDATION_CORE_WORKFLOW.md](VALIDATION_CORE_WORKFLOW.md).

| Item | Code status | Live validation |
| --- | --- | --- |
| Phase C.1 Conversation Intelligence | IMPLEMENTED | **MANUAL PASS** for tested continuation / reference / clarification in Core Daily Workflow. Full Conversation Intelligence coverage remains deferred. |
| Phase C.2 ElevenLabs Realtime («Диалог Beta») | IMPLEMENTED (feature-flagged) | Deferred |
| Google Calendar / Gmail campaign | IMPLEMENTED | Deferred |
| GitHub OAuth + tools campaign | IMPLEMENTED | Deferred (no separate confirmed manual campaign) |
| Onboarding full E2E (completion / profile update) | IMPLEMENTED; entry MANUAL PARTIAL | Deferred |
| Telegram Voice Input (DM voice → Gemini STT → Core) | IMPLEMENTED | Deferred |
| A/B isolation campaign / full IDOR/security campaign | Prepared | Not executed |
| Recurrence / DST / timezone / multi-device reminder edge cases | Recurrence IMPLEMENTED; core reminder flow MANUAL PASS | Deferred exhaustive edges |
| Phase B.2 Tasks / Notification Center / briefs / proactive | IMPLEMENTED | **MANUAL PASS** for the Task Center + reminder chain in Core Daily Workflow. Briefs, proactive, and Notification Center as a full product remain deferred. |
| Telegram Groups analysis (Owner live) | IMPLEMENTED | Deferred |
| Tavily / `fetch_web_page` as a distinct Owner check | IMPLEMENTED | Deferred |
| Screenshot ephemeral purge as a distinct Owner check | IMPLEMENTED | Deferred |
| Destructive Storage delete / destructive storage edge cases | IMPLEMENTED | Deferred |
| Core Reliability historical retry/prune | IMPLEMENTED / CLASSIFIED | Owner decides later; not run |
| Phase E.1 Knowledge Layer | IMPLEMENTED | **MANUAL PASS** for tested core flow. Not all edge cases. |
| Phase E.2 Watchers & Event-driven Automation | IMPLEMENTED | **MANUAL PASS** for internal task watcher flow. External integrations remain deferred. |
| Phase E.2 external watcher campaigns (Gmail / Calendar / GitHub) | IMPLEMENTED | Deferred |
| Phase E.2 proposed action → confirmation → external write | IMPLEMENTED | Deferred |
| Phase E.3 Cross-source Synthesis & Intelligence | IMPLEMENTED | **MANUAL PASS** for tested core synthesis / Overview / waiting / state-change flow. |
| Workspace Presentation | IMPLEMENTED | **MANUAL PASS** after Scenario 8 revalidation on Validation Core 2 |
| Mobile / Client API | DEFERRED | Deferred |
| Optional future integrations | — | Deferred |

## Core Daily Workflow campaign

Owner completed the sequential manual runbook in [VALIDATION_CORE_WORKFLOW.md](VALIDATION_CORE_WORKFLOW.md) on a clean repeat chat **Validation Core 2**.

**CORE DAILY WORKFLOW: MANUAL PASS 10/10** (2026-09-07).

The first Scenario 8 on the earlier campaign chat was a LIVE FAIL (stale derived Overview + presentation). Commit `bcca7ca8ea72181cb6414b2f9d3102178b8b6c63` fixed those defects. Scenario 8 on Validation Core 2 is PASS.

A full campaign PASS does **not** validate every edge case of C.1, E.1, E.2, or E.3. It only narrows the deferred rows to the flows actually exercised.

Still deferred regardless of this PASS:

- ElevenLabs realtime Диалог Beta C.2
- external watcher campaigns
- Gmail live validation
- Google Calendar live validation
- GitHub live validation (no separate confirmed manual campaign)
- Telegram Groups
- external watcher proposed action → confirmation → external write
- DST/timezone edge cases
- destructive storage edge cases
- historical retry/prune campaign
- full IDOR/security campaign
- Mobile / Client API
- optional future integrations

Telegram Voice **Replies** remain MANUAL PASS. Web **Рация** pipeline remains MANUAL PASS. Desktop remains CANCELLED.

When a campaign is run, record the result in CURRENT_STATE. Do not mark MANUAL PASS from code-only work.
