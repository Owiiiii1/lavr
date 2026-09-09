> **Documentation status:** DEFERRED / not current LAVR plan. Telegram WebApp is the intended mobile-class UI ([INTERFACES.md](../INTERFACES.md)). This file is a JARVIS-era companion sketch.

# Mobile companion

**Status.** DEFERRED. Optional future companion to Web/Core. **Not current priority.** Not required for Core roadmap.

Thin client. Source of truth remains Jarvis backend. Mobile does **not** contain Core, Memory Engine, Tool execution, or provider credentials.

Desktop is **CANCELLED**. Mobile does not depend on Tauri, JARVIS-Desktop, Desktop Client API work, or a native-shell prerequisite.

---

## Role

Companion to Web Personal Workspace:

- reliable push
- voice on the go
- camera / photo input
- share-to-Jarvis
- location if permission granted
- quick capture
- mobile notifications

Same `user_id`, same conversations, same tools-on-server.

---

## Repository (if started)

Possible future repo `Owiiiii1/JARVIS-Mobile`. Not created. Flutter remains a reasonable stack if/when this starts. Do not treat that as a commitment.

A versioned Client API is designed **then**, not now. [CLIENT_API.md](CLIENT_API.md).

---

## What Mobile must not do

- Call Google / Gmail / Calendar / GitHub APIs directly
- Store provider tokens
- Run Memory Engine or Tool Registry locally
- Create a second assistant
- Depend on a Desktop client

---

## Voice

Reuse the same `VoiceRuntimeService` contract conceptually. Visualization may differ (not Three.js). Wake word is optional research, not a Web requirement.
