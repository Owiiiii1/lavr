<?php

return [

    /*
    | Voice is a modality over an existing conversation. Hard bounds live here.
    | Admin may choose STT/TTS providers; Conversation AI config is unchanged.
    */

    'stt_provider' => env('VOICE_STT_PROVIDER', 'none'),

    'tts_provider' => env('VOICE_TTS_PROVIDER', 'none'),

    'max_audio_chunk_bytes' => (int) env('VOICE_MAX_AUDIO_CHUNK_BYTES', 2_000_000),

    'max_utterance_seconds' => (int) env('VOICE_MAX_UTTERANCE_SECONDS', 30),

    'max_sessions_per_user' => (int) env('VOICE_MAX_SESSIONS_PER_USER', 2),

    'max_events_per_response' => 24,

    'inactivity_timeout_seconds' => (int) env('VOICE_INACTIVITY_TIMEOUT', 300),

    'session_ttl_seconds' => (int) env('VOICE_SESSION_TTL', 3600),

    'temp_retry_seconds' => (int) env('VOICE_TEMP_RETRY_SECONDS', 120),

    'max_text_for_tts' => (int) env('VOICE_MAX_TEXT_FOR_TTS', 2000),

    'telegram_voice' => [
        'max_spoken_chars' => (int) env('TELEGRAM_VOICE_MAX_SPOKEN_CHARS', 2000),
        'max_code_fence_chars' => (int) env('TELEGRAM_VOICE_MAX_CODE_FENCE_CHARS', 400),
        'max_table_rows' => (int) env('TELEGRAM_VOICE_MAX_TABLE_ROWS', 4),
        // Telegram DM file transcription. Independent of Web max_utterance_seconds.
        // Bytes are the raw file. Gemini inline JSON is larger by base64 (4/3) plus a small margin,
        // so the effective raw cap is the minimum of this value, the API download cap, and that inline budget.
        'max_inbound_bytes' => (int) env('TELEGRAM_VOICE_MAX_INBOUND_BYTES', 20_000_000),
        'max_inbound_seconds' => (int) env('TELEGRAM_VOICE_MAX_INBOUND_SECONDS', 600),
        'stt_timeout_seconds' => (int) env('TELEGRAM_VOICE_STT_TIMEOUT', 90),
        'api_download_max_bytes' => 20_000_000,
        'tts_speed' => (float) env('TELEGRAM_TTS_SPEED', 1.15),
        'tts_speed_min' => 0.70,
        'tts_speed_max' => 1.20,
    ],

    'stt_timeout_seconds' => (int) env('VOICE_STT_TIMEOUT', 20),

    'tts_timeout_seconds' => (int) env('VOICE_TTS_TIMEOUT', 25),

    'connect_timeout_seconds' => (int) env('VOICE_CONNECT_TIMEOUT', 5),

    'audio_disk' => env('VOICE_AUDIO_DISK', 'local'),

    'temp_directory' => 'voice-temp',

    // Telegram TTS temp. Separate from voice-temp: php-fpm creates that as 0700 www-data,
    // while ProcessTelegramUpdate runs as the deploy queue worker.
    'outbound_directory' => 'voice-outbound',

    'allowed_mimes' => [
        'audio/webm',
        'audio/ogg',
        'audio/mpeg',
        'audio/mp3',
        'audio/mp4',
        'audio/m4a',
        'audio/aac',
        'audio/wav',
        'audio/x-wav',
        'audio/flac',
        'audio/3gpp',
    ],

    'spoken_style' => [
        'enabled' => (bool) env('VOICE_SPOKEN_STYLE_ENABLED', true),
        'hint' => env(
            'VOICE_SPOKEN_STYLE_HINT',
            'Response will be spoken aloud; prefer concise natural spoken sentences unless detail is requested.',
        ),
    ],

    'openai_stt' => [
        'model' => env('VOICE_OPENAI_STT_MODEL', 'whisper-1'),
        'base_url' => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
    ],

    'gemini_stt' => [
        'model' => env('VOICE_GEMINI_STT_MODEL', 'gemini-3.5-transcribe'),
        'base_url' => rtrim((string) env('VOICE_GEMINI_STT_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/'),
        // Maximum generateContent request size, including base64 audio. Not a raw-file cap.
        'max_inline_bytes' => (int) env('VOICE_GEMINI_STT_MAX_INLINE_BYTES', 20_000_000),
    ],

    'elevenlabs' => [
        'api_key' => env('ELEVENLABS_API_KEY'),
        'base_url' => rtrim((string) env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io'), '/'),
        'voice_id' => env('ELEVENLABS_VOICE_ID', 'JBFqnCBsd6RMkjVDRZzb'),
        'model_id' => env('ELEVENLABS_MODEL_ID', 'eleven_multilingual_v2'),
        'output_format' => env('ELEVENLABS_OUTPUT_FORMAT', 'mp3_44100_128'),
    ],

    /*
    | Web «Диалог Beta» only. Telegram and legacy «Рация» do not read this block.
    | Disabled unless enabled + agent id + API key + Custom LLM secret are set.
    */
    'realtime' => [
        'enabled' => (bool) env('ELEVENLABS_REALTIME_ENABLED', false),
        'agent_id' => (string) env('ELEVENLABS_AGENT_ID', ''),
        'custom_llm_secret' => (string) env('ELEVENLABS_CUSTOM_LLM_SECRET', ''),
        'signed_url_path' => '/v1/convai/conversation/get-signed-url',
        'adapter_token_ttl_seconds' => (int) env('ELEVENLABS_REALTIME_TOKEN_TTL', 3600),
        'model' => (string) env('ELEVENLABS_REALTIME_MODEL', 'eleven_turbo_v2_5'),
        'expressive' => (bool) env('ELEVENLABS_REALTIME_EXPRESSIVE', true),
    ],

];
