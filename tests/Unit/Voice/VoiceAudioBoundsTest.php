<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\VoiceAudioBounds;
use Tests\TestCase;

class VoiceAudioBoundsTest extends TestCase
{
    public function test_telegram_bounds_are_independent_of_the_web_utterance_limit(): void
    {
        config([
            'voice.max_utterance_seconds' => 30,
            'voice.max_audio_chunk_bytes' => 2_000_000,
            'voice.stt_timeout_seconds' => 20,
            'voice.telegram_voice.max_inbound_seconds' => 600,
            'voice.telegram_voice.max_inbound_bytes' => 20_000_000,
            'voice.telegram_voice.api_download_max_bytes' => 20_000_000,
            'voice.telegram_voice.stt_timeout_seconds' => 90,
            'voice.gemini_stt.max_inline_bytes' => 20_000_000,
        ]);

        $web = VoiceAudioBounds::interactive();
        $telegram = VoiceAudioBounds::telegram();

        $this->assertSame(30_000, $web->maxDurationMs);
        $this->assertSame(2_000_000, $web->maxBytes);
        $this->assertSame(20, $web->timeoutSeconds);
        $this->assertSame(600_000, $telegram->maxDurationMs);
        $this->assertSame(90, $telegram->timeoutSeconds);
        $this->assertGreaterThan(5_000_000, $telegram->maxBytes);
        $this->assertLessThan(20_000_000, $telegram->maxBytes);
    }
}
