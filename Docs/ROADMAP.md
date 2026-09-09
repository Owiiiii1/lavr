> **Documentation status:** DEPRECATED as source of truth for what to build next. JARVIS Phase A–E archive. Canonical plan: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) (LAVR Phases 3A–12). Product: [PRODUCT.md](PRODUCT.md). Runtime: [CURRENT_STATE.md](CURRENT_STATE.md).

# Дорожная карта

Product direction as of **M26D** (2026-09-05). Executable next work: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md). Actual runtime: [CURRENT_STATE.md](CURRENT_STATE.md).

The old four-phase model (Telegram MVP → Memory → Workspace+Desktop+Voice → conversational intelligence) is **HISTORICAL**. It no longer describes what to build next.

**Primary interactive client:** Web Personal Workspace (`/lavr`).  
**Desktop client:** **CANCELLED**.  
**Mobile:** optional future companion, **not** current priority.  
**Voice:** a Web modality. STT/Core/TTS pipeline is **MANUAL PASS**; current capture UX is push-to-talk only.
**Telegram Voice Replies:** MANUAL PASS.  
**Telegram Voice Input:** IMPLEMENTED / NOT VALIDATED.

LAVR is a dedicated single-client instance (not SaaS). Multi-user product surface from JARVIS is removed in Phase 1.

---

## PHASE A — Core Personal Jarvis

**Status.** Mostly complete. Web is the product.

Includes (in code unless noted):

- Identity / single client / no public registration
- Telegram DM pairing + Chat Selector
- Telegram DM pairing + Chat Selector
- Persistent conversations / Conversation Engine
- Shared Personal Workspace
- Admin settings (AI, integrations, Telegram) — not a third-party user catalog
- AI provider abstraction (Owner Conv / Owner Analysis / Default User Conv)
- Personal Memory Engine + Context Budget
- Persistent Storage + ephemeral attachments
- Web Research
- Projects (Owner-only)
- Telegram Groups + group knowledge
- Google Calendar / Gmail tools (Owner)
- GitHub tools (Owner)
- Assistant personalization / onboarding foundation
- Voice (push-to-talk, Gemini STT, per-user ElevenLabs TTS, responsive Orb)
- Reminder engine foundation (channel-independent create; Telegram optional delivery)

### Validation (Owner-confirmed)

| Area | Status |
| --- | --- |
| Ordinary user create / login / `/chat` / basic requests | MANUAL PASS |
| Owner Workspace images, Storage-through-chat, Gemini Google Search | MANUAL PASS |
| Voice start, mic, Gemini STT, reply, ElevenLabs TTS pipeline | MANUAL PASS |
| Push-to-talk-only Web capture + per-user voice choice | IMPLEMENTED |
| Onboarding «Знакомство» entry | MANUAL PARTIAL |
| Full onboarding completion / profile update E2E | IMPLEMENTED / NOT VALIDATED |
| Reminders panel in live user workspace | MANUAL PASS (confirmed live core flow) |
| Reminder create without Telegram | MANUAL PASS (confirmed live core flow) |
| Reminders 2.0 (Web Push, Center v2, recurrence, edit/snooze/done) | MANUAL PASS for confirmed live core flow |
| Tasks / Notification Center / Daily Brief / proactive | IMPLEMENTED / NOT VALIDATED |
| Combined Google / GitHub live smoke | IMPLEMENTED / NOT VALIDATED |
| A/B isolation campaign | PREPARED / NOT EXECUTED |

### Phase A remaining

1. **M25U.3.1 Web Reminders without Telegram** — MANUAL PASS (confirmed live core flow)
2. Full onboarding manual validation — deferred
3. Selected integration manual validation (Google / GitHub) — deferred
4. Optional A/B isolation campaign — deferred

See [DEFERRED_VALIDATION.md](DEFERRED_VALIDATION.md).

---

## PHASE B — Time & Productivity

**Status.** B.1 Reminders 2.0: Owner **MANUAL PASS for confirmed live core flow**. B.2 Tasks & Proactive: **IMPLEMENTED / awaiting Owner validation**.

Jarvis should become time-aware and action-aware, not merely chat-aware.

1. Reminder Core decoupled from Telegram (M25U.3.1)
2. Web Reminder Center v2 — MANUAL PASS (confirmed live core flow)
3. Web Push / browser notifications — MANUAL PASS (confirmed live core flow)
4. Recurring reminders — IMPLEMENTED (not separately Owner-validated for every DST/edge case)
5. Snooze / done / edit — MANUAL PASS (confirmed live core flow)
6. Tasks (separate domain from reminders) — IMPLEMENTED / NOT VALIDATED
7. Task ↔ Reminder relationships — IMPLEMENTED / NOT VALIDATED
8. Task ↔ Conversation relationships — IMPLEMENTED / NOT VALIDATED
9. Notification Center — IMPLEMENTED / NOT VALIDATED
10. Calendar ↔ Tasks ↔ Reminders (optional event reference; no mirror) — IMPLEMENTED / NOT VALIDATED
11. Daily Brief — IMPLEMENTED / NOT VALIDATED
12. Evening / Weekly Review — IMPLEMENTED / NOT VALIDATED
13. Controlled proactive suggestions — IMPLEMENTED / NOT VALIDATED

Detail: [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md).

---

## PHASE C — Natural Conversation

**Status.** Basic Voice pipeline is **complete** (MANUAL PASS). Current capture is push-to-talk.

### C.1 — Conversation Intelligence

**Status.** **IMPLEMENTED / NOT VALIDATED.** Not MANUAL PASS.

Shipped on the existing Conversation Engine (no second Voice/memory/message store):

- topic continuity / return-to-topic / incomplete phrases / pronouns
- clarification policy (clarify writes, not obvious dates)
- conversation working memory vs permanent Memory Engine
- unified personality presentation; temporary style is not a profile write
- bounded conversational initiative (default: answer and stop)
- trusted recent Core tool references for mutation targeting
- frontend stale-response suppression when a new Web message is sent during thinking

Voice and Telegram reuse the same Core path.

### C.2 Beta — ElevenLabs realtime Web voice

**Status.** **IMPLEMENTED / NOT VALIDATED.** Not MANUAL PASS. Legacy «Рация» stays.

- Parallel Web Voice mode «Диалог Beta» (ElevenLabs realtime STT/VAD/barge-in/TTS)
- Jarvis Custom LLM adapter → `ConversationTurnService` (Core remains the brain)
- Telegram Voice Input/Replies unchanged
- Streaming Phase 1: final Core text after the tool loop, then SSE into ElevenLabs
- Legacy removal: **NOT NOW** (Owner A/B first)

Still later: Core token streaming before persist (only after tools), server-side generation cancellation.

**Wake word:** not mandatory. Desktop is cancelled; a wake word in a normal browser has limited product value. Optional future research (mobile/native or always-open environments only).

**Wake word:** not mandatory. Desktop is cancelled; a wake word in a normal browser has limited product value. Optional future research (mobile/native or always-open environments only).

**Telegram voice** (small independent adapter enhancement, not a new phase): outbound `sendVoice` is **MANUAL PASS**. Inbound DM voice notes use existing Gemini STT and the same Conversation Engine (**IMPLEMENTED / NOT VALIDATED**). Default remains **text**. Web remains primary. [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md).

Do **not** imply Desktop, a second Voice assistant, or Telegram becoming the primary client.

[HUMAN_LIKE_ASSISTANT.md](HUMAN_LIKE_ASSISTANT.md), [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md).

---

## PHASE D — Mobile companion

**Status.** DEFERRED. Not current priority. Not a new Core.

Potential value: reliable push, voice on the go, camera / photo, share-to-Jarvis, location if permitted, quick capture, mobile notifications.

Web remains the primary product. No Desktop dependency. [CLIENTS/MOBILE_APP.md](CLIENTS/MOBILE_APP.md).

Versioned Client API is built **only if** Mobile (or another first-party non-Web client) actually starts. [CLIENTS/CLIENT_API.md](CLIENTS/CLIENT_API.md).

---

## PHASE E — Knowledge & Proactive Jarvis

**Status.** E.1 Knowledge Layer: **IMPLEMENTED / NOT VALIDATED**. E.2 Watchers: **IMPLEMENTED / NOT VALIDATED**. E.3 Cross-source Synthesis: **IMPLEMENTED / NOT VALIDATED**. Phase E as a whole is **not** complete.

- Personal Knowledge Layer (structured entities / relations / events with provenance over Memory / Projects / local Core actions — does **not** replace Memory Engine) — E.1 IMPLEMENTED / NOT VALIDATED
- People intelligence (semantic, not a CRM) — E.1
- Richer Project intelligence (index only; Project domain remains canonical) — E.1
- Timeline / activity index — E.1
- Cross-source entity relationships — E.1
- Event-triggered workflows / watchers — E.2 IMPLEMENTED / NOT VALIDATED
- Controlled automations — E.2 notify / internal create / proposed external action (no silent external writes)
- Cross-source synthesis (project/person status, waiting-for, commitments, blockers, attention, daily/weekly consumption of B.2 briefs) — E.3 IMPLEMENTED / NOT VALIDATED
- Proactive assistant (event/condition driven, not unsolicited chatter) — B.2 + E.3 consume attention items under existing caps; still not unsolicited chatter
- Daily / Weekly synthesis — E.3 upgrades existing B.2 brief content; no second cron
- Conditional alerts — E.2 explicit watchers; E.3 derived attention / waiting-for

[KNOWLEDGE_LAYER.md](KNOWLEDGE_LAYER.md). [WATCHERS_AND_AUTOMATIONS.md](WATCHERS_AND_AUTOMATIONS.md). [CROSS_SOURCE_SYNTHESIS.md](CROSS_SOURCE_SYNTHESIS.md).

E.2 examples now in code (fakes only, not live-validated): “When Apple replies, read the mail and say what to do.” “When a GitHub commit lands on this project, notify me.” “If the deadline is tomorrow and the task is open — remind me.”

Strict permissions, confirmation, and audit required. Watchers poll only their own bounded queries. Do not mark all of Phase E complete. Next work is chosen from remaining product gaps (confirmed external actions, clients, live validation of E.1–E.3), not by inventing an E.4 number.

---

## Cancelled

| Item | Decision |
| --- | --- |
| Desktop client / Tauri / JARVIS-Desktop | CANCELLED (ADR-235) |
| System tray / global hotkey / desktop native shell | CANCELLED |
| Desktop-specific auth / API lifecycle | CANCELLED |
| Desktop as prerequisite for Voice / clients / API | CANCELLED |
