> **CURRENT voice runtime.** Interfaces: [INTERFACES.md](INTERFACES.md).

# Голосовая архитектура

**Status.** Legacy Web «Рация» pipeline is MANUAL PASS (Owner, 2026-09-04/05). Web **Диалог Beta** (ElevenLabs realtime) is **IMPLEMENTED / NOT VALIDATED**. Do not treat Beta as MANUAL PASS. Legacy removal is **NOT NOW** — only after Owner live A/B validation.

Voice is a **modality** of Web Personal Workspace over an existing conversation. Not a second Jarvis, second memory, second User Space, or a separate client.

Desktop reuse is **not** a planned path (Desktop CANCELLED). Mobile may later call the same runtime; not current work.

Web Voice has two parallel modes (default **Рация**):

1. **Рация** (legacy, stable) — push-to-talk, Gemini STT, `VoiceRuntimeService`, ElevenLabs HTTP TTS.
2. **Диалог Beta** — browser ElevenLabs realtime transport (STT, turn detection, barge-in, expressive TTS). Jarvis Core remains the only brain via a Custom LLM adapter.

Telegram Voice is a **separate** path and is unchanged: voice note → Gemini STT → `ConversationTurnService`; replies → ElevenLabs HTTP TTS → `sendVoice`. No ElevenAgents, no realtime session, no Twilio, no WebRTC on Telegram.

```
Рация
  hold push-to-talk → MediaRecorder → Gemini STT
  → ConversationTurnService → ElevenLabs TTS → playback

Диалог Beta
  Browser ↔ ElevenLabs realtime
  → Jarvis Custom LLM adapter
  → ConversationTurnService
  → ElevenLabs realtime speech → Browser
```

Do **not** put Memory, Tools, confirmations, or personality into an ElevenLabs Agent brain.

The separate mic button is **mute/unmute**. In Рация the large push-to-talk button controls recording. In Диалог Beta that button is hidden; ElevenLabs owns end-of-turn. Same `conversation_id`. Persistence = ordinary `messages`. No second Voice brain. No continuous audio archive.

```
audio input
  → STT (Gemini on Рация / Telegram; ElevenLabs realtime on Диалог Beta)
  → ordinary user text turn
  → ConversationTurnService
  → tools / memory / web / storage
  → persisted assistant message
  → TTS (HTTP on Рация / Telegram; realtime playback on Диалог Beta)
  → audio output
```

`VoiceRuntimeService` must not call Gemini Conversation AI / `AiChatGateway` directly. The Beta adapter also must not: it only calls `ConversationTurnService`.

UI Orb: [CLIENTS/VOICE_UI.md](CLIENTS/VOICE_UI.md).

### Invariants

- same User Space
- same selected `conversation_id`
- same Conversation Engine
- same AI configuration of that space (STT/TTS do **not** change Conversation AI)
- same assistant personalization profile; TTS Voice ID is a per-user preference (`users.voice_id`) with an instance fallback
- one memory; no `voice_memory` / `voice_messages`
- Text ↔ Voice must not create a new conversation
- final STT text and assistant text are ordinary `messages` rows
- `messages.channel` stays `web`; `messages.metadata.modality = voice`
- Диалог Beta also sets `messages.metadata.voice_mode = realtime`
- one ElevenLabs realtime session binds one Jarvis `conversation_id`; switching chats ends the old session and starts a new one if Voice Beta stays on

---

## Runtime path (M23 + M24.1) — Рация

```
Text → Voice (user gesture)
        ↓
getUserMedia + session ready
        ↓
hold push-to-talk → MediaRecorder
        ↓
release push-to-talk → Blob (canonical MIME + matching filename)
        ↓
POST /jarvis/voice/sessions/{id}/audio  (or /chat/...)
        ↓
VoiceTempAudioStore (ephemeral private disk)
        ↓
SpeechToTextManager → SpeechToTextProvider
        ↓
ConversationTurnService.handleUserMessage
        ↓
ConversationAiService + ContextBudgetManager + tools
        ↓
TextToSpeechManager → TextToSpeechProvider
        ↓
JSON events + optional audio bytes (HTTP)
        ↓
TTS playback ends → ready for the next held turn
```

No continuous vendor stream. No wake word (optional future research only; not Web-mandatory). Mute discards unsent audio. Switching conversation while Voice is active ends the old session.

The normal turn boundary is explicit pointer hold/release, not silence VAD. Holding push-to-talk while Jarvis is speaking first interrupts playback, then records. The configured maximum utterance duration still bounds a held turn.

MIME: `VoiceAudioMime` canonicalizes `audio/webm;codecs=opus` → `audio/webm`. Upload filename matches the container.

`resume` is `muted → idle`. Frontend then calls `listen` exactly once and waits for push-to-talk. Recoverable `voice_session_invalid_state` fetches a snapshot; no full page refresh required.

Domain layer is transport-neutral. Рация uses authenticated session + CSRF HTTP JSON (no Jarvis-owned WebRTC). Диалог Beta uses an ElevenLabs-signed websocket; Jarvis still does not run Twilio, SIP, or Telegram realtime.

M23 generates full assistant text before TTS. Диалог Beta (C.2) still waits for Core to finish the tool loop, then streams that final text into ElevenLabs. Speculative streaming before tools/confirmations is not used.

---

## Диалог Beta (C.2) — ElevenLabs realtime

Web only (`/jarvis`, `/chat`). Feature flag `ELEVENLABS_REALTIME_ENABLED` (default false).

```
POST /jarvis|chat/chats/{conversation}/voice/realtime/session
  → local voice_sessions row (metadata.provider=elevenlabs_realtime)
  → signed URL (xi-api-key stays on the server)
  → opaque adapter token

Browser @elevenlabs/client
  → ElevenLabs realtime audio / STT / VAD / barge-in / TTS

POST /api/voice/elevenlabs/chat/completions
  → Bearer ELEVENLABS_CUSTOM_LLM_SECRET
  → resolve jarvis_session_token → voice_session → user + bound conversation
  → ConversationTurnService
  → SSE of final assistant text
```

API keys never go to the browser. `user_id` / `conversation_id` on the Custom LLM body are ignored. Telegram must not create this session. If realtime is down, the UI offers «Переключиться на Рацию»; it does not dump the live mic into the legacy upload path.

Per-user `users.voice_id` is passed as `overrides.tts.voiceId`. If the Agent catalog cannot match 1:1, Beta speech may use the Agent default; stored `voice_id` semantics are unchanged. Expressive conversational TTS is an Agent/model setting, not Jarvis emotional tags.

---

## Voice Runtime vs Voice UI

**Runtime** (this document): session, STT, TTS, turn pipeline, events, interrupt/mute.

**UI:** Orb, transcript, mute/interrupt/end. One mic = mute.

---

## Provider ports

- `SpeechToTextProvider` → `SpeechTranscript`
- `TextToSpeechProvider` → `SynthesizedSpeech`

Managers: `SpeechToTextManager`, `TextToSpeechManager`. Null providers: `voice_stt_not_configured` / `voice_tts_not_configured`.

STT: `none` | `gemini` | `openai` (Whisper optional).  
TTS: `none` | `elevenlabs`.

Recommended: **STT = Gemini**, **TTS = ElevenLabs**. Conversation AI stays role configs.

### Gemini STT

`models.generateContent` (`v1beta`), **separate** from chat `GeminiClient`. Default model `gemini-3.5-transcribe` (Admin-editable). Live streaming model is **not** used.

Request: `inlineData` + `generationConfig.audioTranscriptionConfig` as a JSON **object** (empty config must be `{}`, not `[]`; a PHP `[]` would serialize as an array and Gemini rejects it). Auto language detection by default. JSON-shape 400s (`unknown name` / `json payload`) are `voice_stt_failed`, not unsupported MIME. Provider logs may include HTTP status and a truncated `error.message`; they must not include audio, transcripts, API keys, or raw request bodies.

STT is instance-level Admin infrastructure. Ordinary users do not configure it.

---

## Sessions

`voice_sessions`: `public_id`, `user_id`, `conversation_id`, `origin` (`web`; enum also lists `desktop`/`mobile` as leftover values, not planned Desktop work), `status`, STT/TTS used, activity timestamps, `error_code`, `metadata`.

Admin infrastructure remains singleton `voice_settings` (providers, key, fallback Voice ID). Each user selects one curated ElevenLabs voice in Workspace settings; the ID is stored as nullable `users.voice_id`. There is no `user_voice_settings` table. Resolution is explicit at Web/Telegram TTS boundaries through `ResolvesUserVoice`. If the selected voice is unavailable on the ElevenLabs account (HTTP 404 or voice-unavailable body markers such as `invalid_voice` / `voice_not_found` / `library voice`), TTS may retry **once** with the instance/config fallback voice. Auth (401/403), quota (402), rate limit (429), connection/transport, and generic 5xx errors must not retry another voice. Fallback failure is surfaced once. Exception context is bounded (`http_status`, `voice_id`, `reason`, `voice_unavailable`) and must not store the raw provider body.

### State machine

`connecting`, `idle`, `listening`, `transcribing`, `thinking`, `speaking`, `interrupted`, `muted`, `error`, `ended`.

Invalid transitions → `voice_session_invalid_state`.

---

## Events

`session.started`, `state.changed`, `listening.started`, `transcript.partial`, `transcript.final`, `assistant.thinking`, `assistant.text`, `audio.started`, `audio.chunk`, `audio.ended`, `interrupted`, `muted`, `resumed`, `error`, `session.ended`.

No provider keys, raw tool JSON, system prompts, or stack traces.

---

## Audio

DTO `VoiceAudioChunk`. Hard bounds in `config/voice.php`.

Ephemeral: private temp disk → STT → delete. Failure: short retry window. `jarvis:voice:cleanup-temp` every five minutes.

Long-term source of truth is the **transcript**, not the recording.

---

## Interruption / mute

Interrupt: cancel TTS playback, state `interrupted`, next utterance. Do **not** delete already-persisted assistant text; set `messages.metadata.voice_playback_interrupted=true`.

Mute = input off. Not session end.

---

## Presentation hint

Optional Admin toggle `spoken_style_enabled`: spoken-aloud brevity. Not a second personality.

---

## Tools, budget, security, observability

Same tools and confirmation policy. Same ContextBudgetManager. Auth: session user owns the session and conversation. Log latencies and byte lengths; **never** audio bytes, transcripts, or secrets.

Errors: `voice_session_not_found`, `voice_session_invalid_state`, `voice_session_limit_reached`, `voice_audio_too_large`, `voice_audio_format_unsupported`, `voice_stt_not_configured`, `voice_stt_failed`, `voice_stt_rate_limited`, `voice_stt_timeout`, `voice_tts_not_configured`, `voice_tts_failed`, `voice_session_expired`, `voice_microphone_unavailable`, `voice_runtime_failed`.

---

## Out of scope

- Telephony / SIP / PSTN
- Wake word as a Web requirement
- Desktop client
- Continuous audio archive
- Telegram Voice Replies as a second Voice Core (outbound delivery is implemented; it is still not Web Voice)

---

## Telegram voice delivery

**Status.** IMPLEMENTED / NOT VALIDATED. [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md).

This is **not** Web Voice. It does **not** use the `voice_sessions` state machine.

Telegram DM text or voice note → Conversation Engine → persist canonical **text** → `TelegramReplyDeliveryService` → existing `TextToSpeechManager` (ElevenLabs MP3, Telegram-only `voice_settings.speed`) → `sendVoice` when the delivery policy says so. ffmpeg is not used.

**Telegram TTS speed** is **IMPLEMENTED / READY FOR OWNER VALIDATION**. Admin setting `telegram_tts_speed` (default **1.15**, range **0.70…1.20**, slider step **0.05**). It is passed only on the Telegram voice-reply path. Web Рация HTTP TTS and Диалог Beta realtime overrides do not inherit it. Fallback voice retries keep the same speed.

Voice notes use existing `SpeechToTextManager` / Gemini STT. No `voice_sessions`.

Default mode `text`. Tools: `get_telegram_response_mode` / `set_telegram_response_mode`. `auto` = voice-in → voice-out, text-in → text-out.

Canonical content is text. Audio is temporary (inbound STT or outbound TTS).

**Telegram Voice Input** is IMPLEMENTED / NOT VALIDATED. **Telegram Voice Replies** are MANUAL PASS.
