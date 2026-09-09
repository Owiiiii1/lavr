> **Documentation status:** DEFERRED. Not a LAVR Phase 3–10 requirement. Telegram WebApp should reuse the existing web session/Workspace, not a new public Client API.

# Client API

**Status.** DEFERRED / NOT CURRENT PRIORITY. Not implemented as a public versioned API.

Do **not** build a versioned Client API because Desktop was once planned. Desktop is cancelled.

Keep this contract as a **future** requirement if/when:

- Mobile development begins, or
- another first-party non-Web client genuinely needs it

**Web uses existing Laravel / Inertia / session routes.** That is the primary product API surface today. See [API.md](../API.md).

---

## Clients never implement locally

- Memory Engine
- Tool execution
- Google / Gmail / Calendar / GitHub credentials
- web search API keys
- group intelligence
- project knowledge assembly
- AI provider selection

---

## If a non-Web client starts

Minimum domains would include: auth, conversations, messages/turns, attachments, storage, voice sessions, tool confirmation, reminders, projects (owner), user/profile, integration status (no secrets).

Ownership: URL id is not enough. Policy checks `user_id`.

Transport (REST vs realtime) is unspecified until a real client exists.

Telegram stays in-process (webhook), not this HTTP API.
