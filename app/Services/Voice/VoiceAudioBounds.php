<?php

namespace App\Services\Voice;

use App\Services\Voice\DTO\VoiceAudioChunk;

/**
 * Interactive Web voice and Telegram file transcription do not share limits.
 * Gemini inline requests count the base64 body, not the raw file.
 */
final readonly class VoiceAudioBounds
{
    public const INTERACTIVE = 'interactive';

    public const TELEGRAM_VOICE = 'telegram_voice';

    /**
     * JSON wrapper left unused inside the Gemini inline request cap.
     */
    private const INLINE_JSON_MARGIN_BYTES = 65_536;

    public function __construct(
        public string $profile,
        public int $maxBytes,
        public int $maxDurationMs,
        public int $timeoutSeconds,
    ) {}

    public static function forChunk(VoiceAudioChunk $chunk): self
    {
        return $chunk->profile === self::TELEGRAM_VOICE ? self::telegram() : self::interactive();
    }

    public static function interactive(): self
    {
        return new self(
            profile: self::INTERACTIVE,
            maxBytes: max(1024, (int) config('voice.max_audio_chunk_bytes', 2_000_000)),
            maxDurationMs: max(1, (int) config('voice.max_utterance_seconds', 30)) * 1000,
            timeoutSeconds: max(2, (int) config('voice.stt_timeout_seconds', 20)),
        );
    }

    public static function telegram(): self
    {
        $api = max(1024, (int) config('voice.telegram_voice.api_download_max_bytes', 20_000_000));
        $inbound = max(1024, (int) config('voice.telegram_voice.max_inbound_bytes', 20_000_000));
        $inline = max(1024, (int) config('voice.gemini_stt.max_inline_bytes', 20_000_000));
        $rawThatFitsInline = (int) floor(max(1024, $inline - self::INLINE_JSON_MARGIN_BYTES) * 3 / 4);

        return new self(
            profile: self::TELEGRAM_VOICE,
            maxBytes: max(1024, min($inbound, $api, $rawThatFitsInline)),
            maxDurationMs: max(1, (int) config('voice.telegram_voice.max_inbound_seconds', 600)) * 1000,
            timeoutSeconds: max(2, (int) config('voice.telegram_voice.stt_timeout_seconds', 90)),
        );
    }
}
