# User administration (M25U.2)

> **SUPERSEDED for LAVR.** Ordinary-user create, User Card, impersonation, and `/chat` are **removed** from the product. LAVR does not register or provision third-party users. Historical JARVIS text follows. See [LAVR_MIGRATION.md](LAVR_MIGRATION.md).

**Status.** Ordinary user create + login + `/chat` + ordinary requests: **HISTORICAL (JARVIS)**. On LAVR this admin surface is gone.

Owner-only management of ordinary Jarvis users. Canonical product doc for lifecycle, isolation, and impersonation. Related: [USERS_AND_CABINET.md](USERS_AND_CABINET.md), [CLIENTS/WEB_WORKSPACE.md](CLIENTS/WEB_WORKSPACE.md).

---

## Product rules

- Ordinary users are **Owner-created only**. There is no `/register`, invite flow, or “Create account” on login.
- Unknown email cannot self-provision. Unknown Telegram access code cannot create a `User`.
- Created accounts always have `role=user`, `status=active`, hashed password, unique Telegram `access_code` (never `2000`).
- Ordinary user login lands on `/chat`. Owner login lands on `/jarvis`. Admin remains `/dashboard`. `/cabinet` is compatibility redirects only.
- `role=user` keeps M25U.1 capabilities: chat, memory, telegram_dm, reminders, personal workspace, profile, web research, voice, storage read/search/chat files.
- `role=user` does **not** get Admin, Projects, integrations, Google, Gmail, GitHub, Telegram Groups, system AI settings, other users, or foreign chats/files/memory.
- Default User Conversation AI only. No per-user model/provider. No fallback to Owner Conversation AI.
- **Disable** is the normal off switch. Hard delete is not a User Card action (data owns conversations, messages, memories, files, attachments, reminders, voice sessions, channel identity).

---

## Admin catalog

`Admin → Settings → Users` (`UsersPanel`) lists Jarvis users, not generic Laravel admin accounts.

Columns: Name, Email, Role, Status, Telegram linked yes/no, Access code, Chats count, Messages count, Last activity, Created at.

Counts use `withCount` / `withMax` (no N+1). Last activity is **derived** (latest of conversation `last_activity_at`, Telegram `last_seen_at`, `users.updated_at`). No `last_activity_at` column was added.

Name opens the User Card: `GET /settings/users/{user}`.

---

## Create user

Owner-only `POST /settings/users`.

Fields: name, email, password + confirmation, timezone (IANA). System sets `role=user`, `status=active`, generates `access_code`.

After create, the User Card shows email, access code, status, timezone. The saved password is **never** redisplayed. Owner must remember the value they typed.

---

## User Card

Tabs: Overview, Access, Chats, Memory.

| Section | Contents |
| --- | --- |
| Profile | name, email, role (read-only), timezone, status, created_at, last activity |
| Usage | chats / messages / memories / stored files / reminders / voice sessions |
| Access | access code, regenerate Telegram code, Telegram linked metadata, unlink, set password |
| Chats | read-only title, last activity, message count (no injection into the user’s conversation) |
| Memory | link to existing Owner diagnostics; General Prompt on the same `user_ai_settings` row |
| Actions | Disable/Enable, Set password, Regenerate Telegram Code, Unlink Telegram, Open as User |

Not exposed: password hash, API keys, raw credentials, integration secrets.

Role cannot be changed from this form. Owner cannot disable, demote, delete, reset password, or regenerate the reserved `2000` code through these endpoints.

---

## Status

Canonical values: `active` | `disabled` (`users.status`).

Disabled user:

- cannot login (generic `auth.failed`);
- existing sessions are deleted for that `user_id` and `user.active` blocks the next request;
- cannot use `/chat`, send turns, or start Voice;
- already-linked Telegram does not invoke AI (system copy only);
- reminders do not create new assistant interactions;
- data is kept. Re-enable is allowed.

If Owner is impersonating a user who becomes disabled, impersonation is stopped and Owner is restored instead of destroying the Owner session.

---

## Passwords

Owner reset from User Card: new password + confirmation, Laravel `Password::defaults()`, hashed via the User `hashed` cast. Plaintext is never stored or logged. After reset, `sessions` rows for that `user_id` are deleted (database session driver) and the remember token is cleared. Other users’ sessions are not touched.

Ordinary users may change their own password from Personal Workspace settings (`PUT /chat/settings/password` or `/jarvis/settings/password`): current + new + confirmation. No account creation, role change, or admin email tools.

---

## Access code

Telegram pairing only. Not the web password.

Regenerate: new unique non-`2000` code; old code cannot pair later; **existing Telegram identity stays linked** until Owner unlinks it.

---

## Telegram unlink

Removes that user’s `channel_identities` row(s) for Telegram. Does not delete the User, conversations, or messages. After unlink, pairing again requires the current access code.

One Telegram identity cannot belong to two Jarvis users (`unique(channel, external_user_id)`). MVP also: one Jarvis user, one Telegram identity (application check in `TelegramPairingService`).

---

## Impersonation

Session-scoped. No DB table.

Session keys: `impersonation.original_owner_user_id`, `impersonation.impersonated_user_id`, `impersonation.started_at`.

Start: Owner-only, target `role=user` and active. `Auth::login(target)` so `/chat` uses the target `user_id`. Original Owner id stays in the session.

While impersonating:

- `/chat` behaves as the user;
- `/dashboard`, `/settings`, `/projects`, integrations stay Owner-gated (`EnsureOwner` sees `role=user`);
- persistent banner: “Viewing as {name}” + Exit impersonation;
- writes (chat, files, settings) **do** mutate that user’s data.

Exit: restore Owner, redirect `/jarvis`. If Owner context is missing/inactive: force login. Structured logs: `impersonation_started` / `impersonation_ended` with internal ids only.

User Card diagnostics (read-only lists) and impersonation (act as the user) are distinct.

---

## Isolation enforcement

Server-side. URL ids are not enough.

| Area | Enforcement |
| --- | --- |
| Conversations | `ConversationService::findOwned` / `ensureOwned` (`user_id` + personal kind) |
| Messages / history | owned conversation first; `ConversationTurnService` checks `conversation.user_id` |
| Attachments | `JarvisAttachmentController` + `ChatAttachmentAccessService` (attachment → message → conversation → user) |
| Stored files | `StoredFileService::findOwnedByPublicId` / `StoredFileSearchService` `where user_id` |
| Memory | retrievers/writer `where user_id`; Owner diagnostics is a separate admin path |
| Voice | `ensureOwned` conversation + `VoiceRuntimeService::ownedSession` (`session.user_id` **and** `conversation.user_id`) |
| Reminders | `ReminderService` `user_id`; delivery uses that user’s Telegram identity; disabled users skipped |
| Telegram | unique identity; pairing looks up existing User; disabled linked users get no AI |
| General Prompt | workspace `updateOrCreate` on `$request->user()->id`; admin User Card targets that user only |
| User admin | `owner` middleware + `UserController::assertUsersAdmin` |
| Impersonation | `ImpersonationService` start is Owner-only; stop restores that Owner |

---

## Manual A/B isolation checklist

**Do not run in this milestone.** Owner will create User A and User B from Admin, then:

A. Login — A → `/chat`; B → `/chat`  
B. Chat isolation — A creates Chat A; B cannot see it  
C. Memory — unique harmless fact for A; new A chat recalls it; B does not  
D. Files — A uploads unique text file; A can retrieve; B cannot  
E. Images — A image upload/vision  
F. Web Search — A current-info query  
G. General Prompt — distinct A/B prompts  
H. Voice — UI/session ownership; live STT/TTS only when Owner decides  
I. Telegram — A/B own pairing code/identity  
J. Disable — disable A; A blocked; B unaffected  
K. Impersonation — Open as A; A workspace; no Admin; Exit restores Owner  

USER SPACE is not MANUAL PASS until this campaign is done.

---

## Follow-ups (not this milestone)

- Hard delete as a separate, guarded admin operation with explicit cascade policy
- Unique DB constraint `(user_id, channel)` in addition to the pairing application check
- Dedicated `last_activity_at` column if derived max becomes expensive
- Live STT/TTS/AI/Telegram/Web Search validation
- Automated PHPUnit / `php artisan test`
