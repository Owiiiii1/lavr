<?php

namespace Tests\Unit\Voice;

use App\Models\AiProviderSetting;
use App\Services\Ai\GeminiCredentialResolver;
use App\Services\Voice\DTO\VoiceAudioChunk;
use App\Services\Voice\Exceptions\VoiceException;
use App\Services\Voice\Providers\GeminiSpeechToTextProvider;
use App\Services\Voice\VoiceAudioBounds;
use App\Services\Voice\VoiceMetricsLogger;
use App\Services\Voice\VoiceSettingsService;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiSpeechToTextProviderTest extends VoiceProviderTestCase
{
    private const AUDIO_MARKER = 'SECRET_AUDIO_MARKER_DO_NOT_LOG';

    private const API_KEY = 'test-gemini-key-do-not-log';

    public function test_empty_transcription_config_is_json_object_not_array(): void
    {
        $this->fakeSuccessfulTranscription();
        $path = $this->writeChunk();

        try {
            $this->provider()->transcribe($this->chunk($path));
        } finally {
            @unlink($path);
        }

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            return str_contains($body, '"audioTranscriptionConfig":{}')
                && ! str_contains($body, '"audioTranscriptionConfig":[]')
                && ! str_contains($body, self::API_KEY);
        });
        Http::assertSentCount(1);
    }

    public function test_json_payload_unknown_name_is_stt_failure_not_unsupported_media(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 400,
                    'status' => 'INVALID_ARGUMENT',
                    'message' => 'Invalid JSON payload received. Unknown name "audioTranscriptionConfig" at \'generation_config\'.',
                ],
            ], 400),
        ]);
        $path = $this->writeChunk();

        try {
            $this->provider()->transcribe($this->chunk($path));
            $this->fail('Expected VoiceException.');
        } catch (VoiceException $exception) {
            $this->assertSame('voice_stt_failed', $exception->error);
        } finally {
            @unlink($path);
        }
    }

    public function test_mime_error_is_classified_as_unsupported_media(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 400,
                    'status' => 'INVALID_ARGUMENT',
                    'message' => 'Unsupported media type for this audio format.',
                ],
            ], 400),
        ]);
        $path = $this->writeChunk();

        try {
            $this->provider()->transcribe($this->chunk($path));
            $this->fail('Expected VoiceException.');
        } catch (VoiceException $exception) {
            $this->assertSame('voice_audio_format_unsupported', $exception->error);
        } finally {
            @unlink($path);
        }
    }

    public function test_logged_context_does_not_include_secrets_audio_or_transcript(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 400,
                    'status' => 'INVALID_ARGUMENT',
                    'message' => 'Invalid JSON payload received. Unknown name "audioTranscriptionConfig".',
                ],
            ], 400),
        ]);
        $path = $this->writeChunk();

        try {
            $this->provider()->transcribe($this->chunk($path));
            $this->fail('Expected VoiceException.');
        } catch (VoiceException) {
            // Expected.
        } finally {
            @unlink($path);
        }

        $serialized = json_encode($logged, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::API_KEY, $serialized);
        $this->assertStringNotContainsString(self::AUDIO_MARKER, $serialized);
        $this->assertStringNotContainsString(base64_encode(self::AUDIO_MARKER), $serialized);
        $this->assertStringNotContainsString('Назови число', $serialized);
    }

    public function test_telegram_profile_transcribes_past_the_web_utterance_limit(): void
    {
        config([
            'voice.max_utterance_seconds' => 30,
            'voice.max_audio_chunk_bytes' => 2_000_000,
            'voice.stt_timeout_seconds' => 20,
            'voice.telegram_voice.max_inbound_seconds' => 600,
            'voice.telegram_voice.max_inbound_bytes' => 20_000_000,
            'voice.telegram_voice.stt_timeout_seconds' => 90,
            'voice.gemini_stt.max_inline_bytes' => 20_000_000,
        ]);
        $timeouts = [];
        Log::listen(function (MessageLogged $event) use (&$timeouts): void {
            if (isset($event->context['timeout_seconds'])) {
                $timeouts[] = $event->context['timeout_seconds'];
            }
        });
        $this->fakeSuccessfulTranscription();
        $path = $this->writeChunk();

        try {
            $transcript = $this->provider()->transcribe($this->chunk(
                $path,
                durationMs: 600_000,
                byteLength: 3_000_000,
                profile: VoiceAudioBounds::TELEGRAM_VOICE,
            ));
        } finally {
            @unlink($path);
        }

        $this->assertSame('Назови число', $transcript->text);
        Http::assertSentCount(1);
        $this->assertSame([90], $timeouts);
    }

    public function test_web_profile_keeps_the_thirty_second_utterance_limit(): void
    {
        config([
            'voice.max_utterance_seconds' => 30,
            'voice.max_audio_chunk_bytes' => 2_000_000,
            'voice.stt_timeout_seconds' => 20,
        ]);
        $this->fakeSuccessfulTranscription();
        $path = $this->writeChunk();

        try {
            $this->provider()->transcribe($this->chunk($path, durationMs: 29_000));
            $this->provider()->transcribe($this->chunk($path, durationMs: 31_000));
            $this->fail('Expected VoiceException.');
        } catch (VoiceException $exception) {
            $this->assertSame('voice_audio_too_large', $exception->error);
        } finally {
            @unlink($path);
        }

        Http::assertSentCount(1);
    }

    private function provider(): GeminiSpeechToTextProvider
    {
        config([
            'voice.stt_provider' => 'gemini',
            'voice.gemini_stt.model' => 'gemini-3.5-transcribe',
            'voice.gemini_stt.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ]);

        $apiKey = self::API_KEY;
        $credentials = new class($apiKey) extends GeminiCredentialResolver
        {
            public function __construct(private readonly string $key) {}

            public function setting(): ?AiProviderSetting
            {
                return null;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function apiKey(): string
            {
                return $this->key;
            }
        };

        return new GeminiSpeechToTextProvider(
            new VoiceSettingsService($credentials),
            $credentials,
            new VoiceMetricsLogger,
        );
    }

    private function fakeSuccessfulTranscription(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Назови число']]],
                    'finishReason' => 'STOP',
                ]],
            ], 200),
        ]);
    }

    private function writeChunk(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'jarvis-voice-');
        file_put_contents($path, self::AUDIO_MARKER);

        return $path;
    }

    private function chunk(
        string $path,
        int $durationMs = 800,
        ?int $byteLength = null,
        string $profile = VoiceAudioBounds::INTERACTIVE,
    ): VoiceAudioChunk {
        return new VoiceAudioChunk(
            sessionPublicId: 'sess-test',
            sequence: 1,
            absolutePath: $path,
            byteLength: $byteLength ?? strlen(self::AUDIO_MARKER),
            mime: 'audio/ogg',
            sampleRate: 48000,
            channels: 1,
            isFinal: true,
            durationMs: $durationMs,
            capturedAt: null,
            profile: $profile,
        );
    }
}
