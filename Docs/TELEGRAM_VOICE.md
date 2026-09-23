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

**Application limits.** Telegram DM voice is a file transcription, not a Web push-to-talk turn. It does **not** use `max_utterance_seconds` or `max_audio_chunk_bytes`. Web Рация / Dialog Beta keep those short-turn limits (default **30 seconds** / **2 MB**).

| Bound | Default | Config |
| --- | --- | --- |
| Duration | **10 minutes** (600s) | `voice.telegram_voice.max_inbound_seconds` |
| Raw file size | min of configured bytes, API cap, and the Gemini inline budget | `voice.telegram_voice.max_inbound_bytes` (default 20_000_000) |
| STT wait | **90 seconds**, one attempt | `voice.telegram_voice.stt_timeout_seconds` |
| Telegram API ceiling | 20 MB | `voice.telegram_voice.api_download_max_bytes` |

`gemini_stt.max_inline_bytes` is the generateContent request cap, including base64. Raw audio is limited to about three quarters of that cap minus a 64 KiB JSON margin, so a 20 MB raw file is not sent. A typical 5–10 minute Opus note is far smaller and is sent as **one** STT request. There is no 30-second split and no ffmpeg.

A note inside the configured duration is a normal turn. Over the duration: one text reply, «Голосовое слишком длинное. Максимальная длительность — 10 минут.» No byte or API wording. Audio is not truncated. STT is not started.

Live transcription of a 10-minute note is **not** validated. Automated tests cover the limit split only.

**MIME.** Canonicalize Telegram `mime_type` if sane; otherwise finfo on the downloaded file; typical Telegram voice note falls back to `audio/ogg`. `audio/opus` / `application/ogg` alias to `audio/ogg`. Gemini STT already lists `audio/ogg`. **No ffmpeg.**

**STT.** `SpeechToTextManager` → configured provider (`GeminiSpeechToTextProvider`). Same encrypted Gemini credential and model as Web Voice. Not `AiChatGateway`. Model ID was not changed for Telegram.

**Transcript.** Trim only. Empty / whitespace → text retry (`VOICE_EMPTY`). No LLM “cleanup”, no translation. Canonical persisted user body is the transcript. Metadata (no migration): `modality=voice`, `source=telegram`, `source_mime`, `duration_seconds`. `file_id` is not stored.

**Idempotency.** `messages` unique (`channel`, `conversation_id`, `channel_message_id`). Lookup runs **before** download/STT. Duplicate with an assistant reply: silent skip. Duplicate user row without assistant: resume `ConversationTurnService` from stored transcript (no second STT). Job: `ProcessTelegramUpdate` `tries=2`, `timeout=170` on queue `default` (under the worker `--timeout=180`). Web STT may retry a connection failure once. Telegram file STT uses one attempt so a long note stays inside that job.

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
