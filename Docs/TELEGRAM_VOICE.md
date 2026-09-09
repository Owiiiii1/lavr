> **CURRENT Telegram voice adapter.** Fast channel: [INTERFACES.md](INTERFACES.md).

# Telegram Voice

**Telegram Voice Replies:** MANUAL PASS (Owner confirmed a live `sendVoice` bubble).  
**Telegram Voice Input:** IMPLEMENTED / NOT VALIDATED.  
**Web Voice:** MANUAL PASS.

Not a second Voice Core. No `voice_sessions` for Telegram. Same Conversation Engine, Memory, tools, Assistant Profile, General Prompt.

Related: [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md), [CHANNELS.md](CHANNELS.md).

---

## Two directions

| | Direction | Status |
| --- | --- | --- |
| **A. Telegram Voice Input** | User voice note → existing Gemini STT → Core | **IMPLEMENTED / NOT VALIDATED** |
| **B. Telegram Voice Reply** | Assistant text → existing ElevenLabs TTS → `sendVoice` | **MANUAL PASS** |

---

## Architecture

```
Telegram DM voice note (Message.voice only)
  → TelegramUpdateHandler (same pairing / active / identity checks as text)
  → duplicate channel_message_id? skip download/STT
  → TelegramNutgramVoiceDownloader (Nutgram getFile + downloadFile)
  → SpeechToTextManager → GeminiSpeechToTextProvider
  → transcript (trimmed)
  → ConversationTurnService::handleUserMessage
  → TelegramReplyDeliveryService (inboundModality = voice)
       text | unsuitable | TTS fail | sendVoice fail → sendMessage
       voice success → sendVoice (no duplicate text)

Telegram DM text
  → same handler / turn / delivery with inboundModality = text
```

Groups: unchanged. Voice notes persist as `[voice]` placeholder. No STT. No auto-reply.

Reminders: unchanged.

---

## Preference

Table `user_channel_preferences` (`user_id` + `channel`, unique).  
`response_mode`: `text` | `voice` | `auto`. **Default: `text`.**

| Mode | Text inbound | Voice inbound |
| --- | --- | --- |
| `text` | text reply | text reply |
| `voice` | voice reply when suitable | voice reply when suitable |
| `auto` | text reply | voice reply when suitable |

`auto` uses the explicit inbound modality (`text` \| `voice`), not “whether a transcript exists”.

Tools (current user only, capability `telegram_dm`): `get_telegram_response_mode`, `set_telegram_response_mode`.

---

## Voice Input (A)

**Scope.** Paired private DM `Message.voice` only. Not `video_note`, not audio/music, not documents, not groups.

**Official Telegram:** Voice has `file_id`, `file_unique_id`, `duration`, optional `mime_type` / `file_size`. `getFile` then download. Cloud Bot API download limit is **20 MB**. Application limits are stricter.

**Application limits (aligned with current Web/Gemini STT):**

| Bound | Value | Config |
| --- | --- | --- |
| Duration | **30 seconds** | `voice.telegram_voice.max_inbound_seconds` |
| Size | **2_000_000 bytes** | `voice.telegram_voice.max_inbound_bytes` |
| Telegram API ceiling | 20 MB | `voice.telegram_voice.api_download_max_bytes` (not the product limit) |

Oversize / too long: short **text** error. Audio is not truncated. STT is not started.

**MIME.** Canonicalize Telegram `mime_type` if sane; otherwise finfo on the downloaded file; typical Telegram voice note falls back to `audio/ogg`. `audio/opus` / `application/ogg` alias to `audio/ogg`. Gemini STT already lists `audio/ogg`. **No ffmpeg.**

**STT.** `SpeechToTextManager` → configured provider (`GeminiSpeechToTextProvider`). Same encrypted Gemini credential and model as Web Voice. Not `AiChatGateway`. Model ID was not changed for Telegram.

**Transcript.** Trim only. Empty / whitespace → text retry (`VOICE_EMPTY`). No LLM “cleanup”, no translation. Canonical persisted user body is the transcript. Metadata (no migration): `modality=voice`, `source=telegram`, `source_mime`, `duration_seconds`. `file_id` is not stored.

**Idempotency.** `messages` unique (`channel`, `conversation_id`, `channel_message_id`). Lookup runs **before** download/STT. Duplicate with an assistant reply: silent skip. Duplicate user row without assistant: resume `ConversationTurnService` from stored transcript (no second STT). Job: `ProcessTelegramUpdate` `tries=2`, `timeout=75` on queue `telegram`. STT manager has its own 2-attempt retry for connection/5xx only.

**Temp audio.** Private `voice-outbound/telegram/inbound/{userId}/{random}.ogg` (queue worker writable). Deleted after STT success or failure. Stale: `jarvis:voice:cleanup-temp`. Not StoredFile / MessageAttachment / public disk.

**Errors.** Always text. No voice error bubble. No bogus AI turn. Download/STT failures do not fail the webhook worker.

**Webhook ACK.** Unchanged: controller dispatches `ProcessTelegramUpdate`; download+STT run on the telegram queue, not in the HTTP request.

---

## Voice Reply (B)

Telegram `sendVoice` accepts OGG/OPUS, **MP3**, M4A. ElevenLabs returns MP3. HTTP multipart `sendVoice` via `TelegramBotManager` (no reply keyboard on the voice file). Fallback: one `sendMessage`. Canonical assistant text is never deleted.

**Telegram TTS speed:** **IMPLEMENTED / READY FOR OWNER VALIDATION**. Admin → Voice/Speech → **Telegram TTS speed**. Default **1.15** (slightly faster than ElevenLabs 1.00). Range **0.70…1.20**. Applies only to generated Telegram voice replies. Web Voice / realtime unchanged.

Outbound temp: `voice-outbound/` (deploy queue worker). Web inbound chunks still use `voice-temp/` (php-fpm).

---

## Out of scope (unchanged)

- Telegram Groups STT / auto-reply
- Reminder dispatch
- Scheduled report dispatch (same Telegram sender; not a second bot)
- Disabled users (existing reject path; no STT)
- Desktop / Mobile / Client API
- ffmpeg
- Raw audio archive
