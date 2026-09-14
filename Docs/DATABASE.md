# Database (actual schema)

> **CURRENT tables.** See `database/migrations/` and [CURRENT_STATE.md](CURRENT_STATE.md). People/organizations (Phase 4), meetings (Phase 5A), first-class `commitments` (Phase 6), and Phase 11 `operational_events` / `proactive_proposals` are in MySQL. First-class `decisions` remain TARGET.

**Status.** Snapshot 2026-09-05 (plus later migrations in git). Source of truth: `database/migrations/`. This file lists **what exists**; it may omit post-snapshot tables (watchers, scheduled_reports). Code wins.

Engine: MySQL. CRM leftover tables were dropped. Vector DB is not used.

---

## Identity

**users:** `role` owner\|user, unique `access_code` (owner `2000`), `status` active\|disabled, IANA `timezone`, password hash (not the access code).

**user_ai_settings:** unique `user_id`, `general_prompt`, unused `overrides` json.

**user_assistant_profiles:** unique `user_id`; `assistant_name`, `personality`, `interaction_style`, `about_user`, onboarding fields.

**user_channel_preferences:** unique (`user_id`, `channel`); `response_mode` text\|voice\|auto. Missing row = Telegram **text**. Not personality.

**user_profiles:** derived Memory summary (not the assistant profile).

**channel_identities:** Telegram (and future channels) ↔ user; `active_conversation_id`.

---

## Conversations

**conversations:** `user_id`, `kind` direct\|group, title, status, `last_activity_at`.

**messages:** conversation, user, optional `telegram_group_id`, role, channel, body, types, `channel_message_id`, parent, metadata, `occurred_at`.

**message_attachments:** ephemeral/private attachments + lifecycle.

---

## Memory

`conversation_summaries`, `topics`, `message_topic_relations`, `memories`, `memory_sources`, `memory_revisions`, `memory_analysis_runs`.

---

## Reminders

**reminders:** `user_id`, source conversation/message, optional `task_id`, text, `run_at` UTC, timezone, status scheduled\|processing\|delivered\|completed\|cancelled\|failed, `completed_at`, `recurrence_rule` (`daily`/`weekdays`/`weekly`/`monthly`).

**reminder_deliveries:** per-channel (`telegram`/`web_push`) status, attempts, timestamps.

**reminder_occurrences:** fired occurrence history for recurring series.

**push_subscriptions:** user-owned Web Push endpoints; encrypted `p256dh`/`auth`.

---

## Tasks / Notification Center (Phase B.2)

**tasks:** `user_id`, optional `parent_task_id`, title, description, status `open|in_progress|completed|cancelled`, priority `low|normal|high|urgent`, `due_at` UTC, timezone, source conversation/message, optional `project_id`, optional calendar reference (`calendar_provider`, `calendar_id`, `calendar_event_id`), `completed_at`, `cancelled_at`, metadata.

**jarvis_notifications:** `user_id`, type, title, body, severity, source_type/id, unique `dedupe_key`, safe `action_url`, `read_at`, `dismissed_at`, `occurred_at`, `ai_phrased`, metadata.

**user_productivity_settings:** per-user opt-in Daily/Evening/Weekly clocks and `proactive_enabled` (all default false).

---

## Storage

`stored_files`, `stored_file_chunks`, `message_stored_files`.

---

## Voice

**voice_settings:** singleton STT/TTS providers, `stt_model`, spoken style, encrypted ElevenLabs key + voice id, `telegram_tts_speed` (nullable decimal 0.70…1.20; app default 1.15).

**voice_sessions:** `public_id`, user, conversation, origin, status, providers used, timestamps, error, metadata.

---

## Projects (Owner)

`projects` plus pivots `project_conversations`, `project_topics`, `project_memories`, `project_groups`.

---

## Telegram groups

`telegram_groups`, `telegram_group_participants`, analysis runs, `telegram_group_knowledge` + sources/revisions.

---

## Integrations / tools / settings

`ai_provider_settings`, `ai_role_settings`, `telegram_bot_settings`, `web_research_settings`, `google_oauth_settings`, `integration_accounts`, `source_items`, `project_source_bindings`, `tool_execution_logs`, `tool_confirmations`.

**google_oauth_settings:** singleton Admin Google OAuth client configuration. `client_id` and `redirect_uri` are plain strings. `client_secret` is encrypted at rest (Laravel `encrypted` cast). Not OAuth user tokens.

---

## Commitments (Phase 6)

**commitments:** Owner-owned promises. `lifecycle_status` is durable; `status` is computed effective (`due_soon` / `overdue` only while open). Unique `(user_id, fingerprint)`. Nullable `person_id` with `unresolved_person`. Canonical completion status is `confirmed`.

**commitment_evidence:** short excerpts only. Types include promise / completion / confirmation.

**commitment_status_history:** from/to status, reason, optional `changed_by`.

**executive_briefs:** Owner morning (also evening/weekly types). `sections_json` + `source_snapshot_json` (counts/errors, no bodies). Unique `(user_id, run_key)`.

---

## Framework

`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`, `migrations`.
