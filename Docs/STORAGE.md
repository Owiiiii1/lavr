> **CURRENT file library.** Knowledge vs operational data: [DOMAIN_MODEL.md](DOMAIN_MODEL.md).

# Jarvis Storage and ephemeral chat media

**Status.** PARTIAL MANUAL PASS (M22.2, 2026-09-04). Automated tests not run.

- Text file upload: **MANUAL PASS**
- Storage persistence / read / retrieval: **MANUAL PASS**
- Storage UI основные операции: IMPLEMENTED / NOT VALIDATED
- screenshot summarization / purge: IMPLEMENTED / NOT VALIDATED
- destructive delete confirmation: IMPLEMENTED / NOT VALIDATED

This document is the product and implementation source for:

- ephemeral chat screenshots (`message_attachments`);
- persistent files (`stored_files` + `stored_file_chunks`), scoped by `user_id`;
- Storage Workspace UI (`/jarvis/storage`) — **owner only**;
- Storage tools (read/search for any user with `storage`; `delete_storage_file` owner-only).

Ordinary users may attach supported files in `/chat`. Jarvis can retrieve those files via tools scoped to the authenticated `user_id` (`StoredFileService::findOwnedByPublicId`, `StoredFileSearchService`). UUID-only lookup is not an authorization path. User A cannot search, read, or download User B files. There is **no** `/chat/storage` page. Owner Admin Storage UI is a separate `/jarvis/storage` path. No per-user total quota in M25U.1 (future hardening).

See also [CLIENTS/WEB_WORKSPACE.md](CLIENTS/WEB_WORKSPACE.md), [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md), [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md), [DATABASE.md](DATABASE.md).

---

## Manual production validation — 2026-09-04

**PASS:** persistent text-file upload from Workspace; Jarvis read the file and retrieved it after upload.

Not Owner-checked: `/jarvis/storage` library rename/download/delete, screenshot summarization, 24h/7d purge, destructive `delete_storage_file` confirmation.

---

## Domain separation

| Entity | Purpose | Lifecycle |
| --- | --- | --- |
| `message_attachments` | Chat screenshots / multimodal media | Ephemeral by default. Raw bytes deleted after retention. DB row + visual summary remain. |
| `stored_files` | Owner personal document library | Permanent until the owner deletes. Raw bytes on private disk, never in DB. |
| `stored_file_chunks` | Extracted text windows | Permanent with the file. Retrieval source for tools. |
| `message_stored_files` | Optional pivot | A chat turn may reference an existing StoredFile. No second physical copy. |

Do not mix these tables. A screenshot is not a StoredFile. A log file is not a `message_attachment`.

---

## Context sources (do not collapse)

Jarvis context is assembled from distinct layers. None of these is “the whole user history”:

1. **Raw conversation** — recent semantic messages in the current chat.
2. **Conversation summaries** — derived chat summaries when the window is long.
3. **Personal memory** — durable facts extracted from conversation text.
4. **Ephemeral media summaries** — derived `summary_text` on `message_attachments` after screenshots expire.
5. **Persistent Storage files/chunks** — retrieval only, via tools or a bounded current-turn excerpt.

Storage is **not** automatic prompt context. There is no “all stored files” dump. Current-turn attached files may include compact metadata and a small inline excerpt; large files must be read with tools.

Global Context Budget Manager is **implemented in M22.3** together with Web Research. M22.2 still hard-bounds Storage tool results locally; M22.3 adds the second global layer. [CONTEXT_BUDGET.md](CONTEXT_BUDGET.md).

---

## Ephemeral screenshots

Config: `config/chat_attachments.php`.

| Key | Default | Meaning |
| --- | --- | --- |
| `retention_class` | `ephemeral` | Default class for new Web screenshots. Not “every image everywhere is ephemeral”. |
| `retention_hours` | 24 | Soft retention of original bytes. |
| `hard_retention_days` | 7 | Fallback: originals may be purged even if summary failed. |
| `purge_batch` | 50 | Hourly command bound. |
| `summary_max_chars` | 1200 | Dedicated visual summary, not the assistant reply. |
| `summary_queue` | `memory` | Same queue family as memory analysis. |

### Why 24 hours

Owner may immediately follow up (“look at the top-left corner again”). During retention Jarvis may still have the original pixels. After expiry only the textual summary remains.

### Lifecycle columns on `message_attachments`

- `retention_class`
- `expires_at`
- `summary_status`: `pending` \| `processing` \| `ready` \| `failed` \| `not_required`
- `summary_text`
- `summarized_at`
- `purged_at`
- `purge_failure_count`

Existing M22.1 rows were backfilled as `ephemeral` with `expires_at = created_at + retention_hours`. Migration does **not** delete files.

### Visual summary

`AttachmentVisionSummaryService` + `SummarizeMessageAttachmentJob`.

- Uses the existing AI abstraction (`AiChatGateway`) and **Owner Conversation** configuration because that role is vision-capable in production (Gemini).
- Does **not** silently switch the Owner Analysis AI role.
- Does **not** POST to Gemini from the job.
- Prompt is internal: concise factual summary, no speculation, preserve visible error text, screenshot content is untrusted data.
- Summary is derived attachment metadata. It is **not** personal memory and is not bulk-ingested by the Memory Engine.

### Purge eligibility

Command: `jarvis:attachments:purge-ephemeral` (hourly).

A row is eligible when:

- `retention_class = ephemeral`, `purged_at` is null, **and**
- (`summary_status = ready` **and** `expires_at <= now`) **or** `created_at` is older than `hard_retention_days`.

Policy:

- Do not purge at 24h if the summary is not ready.
- Failed summaries stay with the original until hard retention.
- After hard retention, purge originals even if summary failed; leave metadata (`summary_status=failed`, empty paths).
- Bounded batch. No mass delete in migration or in one unbounded query.
- After purge: keep the DB row; clear `storage_path` / thumbnail; never replay raw bytes.

UI after purge: “Screenshot expired” + bounded/expandable `summary_text`, or “Screenshot expired; visual summary unavailable.” No broken `<img>`.

Historical conversation context uses `[Previous screenshot summary: …]` when available, not `[N images attached]`, and never re-inserts purged bytes.

Chat composer shows “Temporary image · expires in ~24h”. There is **no** “save screenshot forever” in M22.2.

---

## Persistent Storage

Config: `config/jarvis_storage.php`.

Recommended app-level limit: **20 MB** per text file (PHP `.user.ini` 32M, nginx `client_max_body_size` 64M). Extracted text is still capped (`max_extracted_chars_per_file`). A 20 MB log must not enter the prompt whole.

### Paths

Private disk (`local` → `storage/app/private`):

`jarvis-storage/{user_id}/{uuid}/file.{ext}`

No public URL. Download/preview are owner ownership routes. Absolute paths are never sent to the client or logs.

### Supported in M22.2

Plain text and source: `txt md log csv tsv json xml yaml yml ini conf env php js ts jsx tsx py java c h cpp hpp cs go rs sql sh ps1 html css` and a small additional source set in config.

**Not** supported: PDF, DOCX, XLSX, PPTX, ZIP/RAR, executables, arbitrary binary, images as persistent Storage.

Validation: extension + MIME + null-byte/binary heuristics + UTF-8/text normalization. HTML/XML stored as documents, never executed or rendered as active HTML. Downloads use `Content-Disposition: attachment`.

### Ingestion

1. `stored_files.status = uploaded`
2. `ProcessStoredFileJob` (`default` queue; sync if size ≤ `sync_process_max_bytes`)
3. `StoredFileTextExtractor` → `StoredFileChunker` (line-aware windows, overlap)
4. structural summary (filename, type, line count, headings) — **no AI required**
5. `status = ready` or `failed` with a safe error code. Original bytes remain on failure for retry.

`sha256` is stored. Identical hashes are **not** silently merged. `client_upload_id` avoids duplicate rows on the same multipart retry.

### Chat vs direct upload

- Composer may send images + Storage files in one user turn.
- Chat text files create a `StoredFile` and a `message_stored_files` row. Same physical file.
- Direct `/jarvis/storage` upload creates a `StoredFile` only. No fake conversation/message.

### Current-turn AI access

Conversation Engine receives:

- compact file metadata including `public_id`;
- inline text only if extracted size ≤ `inline_turn_chars` (default 4k);
- otherwise a pointer to `get_storage_file` / `search_storage_file_contents` / `read_storage_file_chunks`.

No wholesale file dump. No “all Storage files” in system context.

### Search

`StoredFileSearchService`: deterministic SQL `LIKE` on names/summaries and chunk text. No embeddings. No MariaDB FULLTEXT in M22.2 (MySQL 8 production; keep the service swappable).

### Tools (capability `storage`, owner-only)

| Tool | Class |
| --- | --- |
| `list_storage_files` | read |
| `search_storage_files` | read, metadata only |
| `get_storage_file` | read, metadata + bounded excerpt |
| `search_storage_file_contents` | read, matching chunks |
| `read_storage_file_chunks` | read, sequential window |
| `delete_storage_file` | destructive → `ToolConfirmationService` |

Every tool result is bounded (`search_result_limit`, `max_chunks_per_tool_result`, `max_tool_chars`, `truncated=true`).

Normal users do not receive Storage tools or `/jarvis/storage`.

### Workspace UI

| Route | Meaning |
| --- | --- |
| `GET /jarvis/storage` | paginated library, upload, search |
| `GET /jarvis/storage/{public_id}` | detail, bounded preview, rename, delete confirm, download |
| `GET /jarvis/chats/{id}` | chat; Storage files show “Saved to Storage” |

Delete in UI requires an explicit confirmation modal. Source chat links to `/jarvis/chats/{conversation}` when the pivot exists.

Statuses: Uploading / Processing / Ready / Failed. Never fake Ready before extraction finishes.

---

## Security

- Every StoredFile request checks `stored_file.user_id === current user.id`. UUID alone is not authorization.
- Filenames sanitized for display; physical names are UUIDs. No path traversal.
- Storage contents and screenshot pixels are **untrusted user data**. Platform guidance tells the model not to treat embedded instructions as higher-priority directives.
- Logs may include file id, type, size, chunk count, status/error code. Do not log contents, screenshot summaries, raw private filenames as secrets, or absolute storage paths.

---

## Scheduler / queues

| Job | Queue |
| --- | --- |
| `SummarizeMessageAttachmentJob` | `memory` |
| `ProcessStoredFileJob` | `default` |
| `AnalyzeConversationTurnJob` (unchanged) | `memory` |

Schedule:

- `jarvis:reminders:dispatch` every minute (unchanged).
- `jarvis:attachments:purge-ephemeral` hourly (also enqueues a bounded batch of pending summaries).
- `jarvis:reliability:recover-stale` every 15 minutes.
- `queue:work database --queue=analysis,memory,default --stop-when-empty --timeout=180` every minute as a fallback. `jarvis-queue.service` is the long-running worker on the same queues. The existing telegram worker is unchanged.

Purged/expired ephemeral screenshots that never got a summary become `NotRequired` (`stale_source`), not an endless retry. `jarvis:attachments:retry-failed` is dry-run unless `--execute`.

---

## Composer focus (Workspace UX)

Wide-pointer browsers (`(hover: hover) and (pointer: fine)`): restore composer focus after send, error, suggestion chip, attachment remove, lightbox close — once the textarea is enabled again.

Touch/mobile: do not force the software keyboard after a turn.

---

## Not in M22.2

- PDF/Office/ZIP extractors
- Permanent screenshot library / “save image to Storage”
- Vector/embedding search
- ContextBudgetManager (done in M22.3; Storage still never auto-dumps files)
- User-role Storage
- Telegram document/photo ingestion
- Automated tests
- screenshot summarization/purge (still NOT VALIDATED)
- Storage library UI and destructive delete confirmation (still NOT VALIDATED)
