> **Documentation status:** HISTORICAL. Four-phase JARVIS archive. Canonical plan: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md).

# Этапы разработки (historical archive)

**Status.** HISTORICAL / SUPERSEDED as a planning model (M26D).

The original four phases (Telegram MVP → Memory → Workspace + native clients + Voice → conversational intelligence) described how Jarvis was built. They are **not** the current roadmap.

**Use instead:**

- [ROADMAP.md](ROADMAP.md) — Phases A–E
- [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) — next work
- [CURRENT_STATE.md](CURRENT_STATE.md) — what exists
- [DECISIONS.md](DECISIONS.md) — ADRs

Corrections vs this archive:

- Desktop / Tauri / Phase 3 “native clients” → **CANCELLED** for Desktop
- Basic Voice STT/Core/TTS pipeline — **done**; current Web capture is push-to-talk and hands-free/VAD capture was removed
- Web Personal Workspace is the primary client, not a stepping stone to Desktop
- Reminder Telegram-only create/delivery is **current code**, not the target architecture
