<?php

namespace App\Services\Voice\Providers;

use App\Services\Ai\GeminiCredentialResolver;
use App\Services\Voice\Contracts\SpeechToTextProvider;
use App\Services\Voice\DTO\SpeechTranscript;
use App\Services\Voice\DTO\VoiceAudioChunk;
use App\Services\Voice\Exceptions\VoiceException;
use App\Services\Voice\VoiceAudioBounds;
use App\Services\Voice\VoiceAudioMime;
use App\Services\Voice\VoiceMetricsLogger;
use App\Services\Voice\VoiceSettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class GeminiSpeechToTextProvider implements SpeechToTextProvider
{
    public function __construct(
        private readonly VoiceSettingsService $settings,
        private readonly GeminiCredentialResolver $credentials,
        private readonly VoiceMetricsLogger $metrics,
    ) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function isConfigured(): bool
    {
        return $this->credentials->isConfigured() && $this->settings->sttModel() !== '';
    }

    public function transcribe(VoiceAudioChunk $chunk, ?string $language = null): SpeechTranscript
    {
        $apiKey = $this->credentials->apiKey();
        $model = $this->settings->sttModel();

        if ($apiKey === '' || $model === '') {
            throw VoiceException::sttNotConfigured();
        }

        $mime = VoiceAudioMime::forGemini($chunk->mime);

        if ($mime === null) {
            throw VoiceException::audioFormatUnsupported([
                'canonical_mime' => VoiceAudioMime::canonicalize($chunk->mime),
                'raw_mime' => $chunk->mime,
                'audio_bytes' => $chunk->byteLength,
                'extension' => VoiceAudioMime::extension($chunk->mime),
            ]);
        }

        $this->assertBounded($chunk);

        $binary = @file_get_contents($chunk->absolutePath);

        if (! is_string($binary) || $binary === '') {
            throw VoiceException::sttFailed();
        }

        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [[
                    'inlineData' => [
                        'mimeType' => $mime,
                        'data' => base64_encode($binary),
                    ],
                ]],
            ]],
            'generationConfig' => [
                'audioTranscriptionConfig' => $this->audioTranscriptionConfig($language),
            ],
        ];

        $started = microtime(true);
        $base = $this->settings->geminiSttBaseUrl();
        $url = $base.'/models/'.$this->sanitizeModel($model).':generateContent';

        try {
            $response = $this->postWithRetry($url, $apiKey, $payload, VoiceAudioBounds::forChunk($chunk));
        } catch (VoiceException $exception) {
            $this->logOutcome($chunk, $mime, $model, $started, $exception->error);

            throw $exception;
        } catch (Throwable) {
            $this->logOutcome($chunk, $mime, $model, $started, 'voice_stt_failed');

            throw VoiceException::sttFailed();
        }

        $status = $response->status();

        if ($status === 429) {
            $this->logOutcome($chunk, $mime, $model, $started, 'voice_stt_rate_limited');

            throw VoiceException::sttRateLimited();
        }

        if (in_array($status, [408, 504], true)) {
            $this->logOutcome($chunk, $mime, $model, $started, 'voice_stt_timeout');

            throw VoiceException::sttTimeout();
        }

        if (in_array($status, [400, 415], true) && $this->looksLikeUnsupportedMedia($response)) {
            $this->logOutcome($chunk, $mime, $model, $started, 'voice_audio_format_unsupported', [
                'gemini_status' => $status,
                'gemini_message' => Str::limit((string) $response->json('error.message', ''), 240),
            ]);

            throw VoiceException::audioFormatUnsupported();
        }

        if (! $response->successful()) {
            $this->logOutcome($chunk, $mime, $model, $started, 'voice_stt_failed', [
                'gemini_status' => $status,
                'gemini_message' => Str::limit((string) $response->json('error.message', ''), 240),
            ]);

            throw VoiceException::sttFailed();
        }

        $transcript = $this->normalizeTranscript($response->json() ?? [], $model, $language);

        if ($transcript->text === '') {
            $this->logOutcome($chunk, $mime, $model, $started, 'voice_stt_failed');

            throw VoiceException::sttFailed();
        }

        $this->logOutcome($chunk, $mime, $model, $started, 'ok');

        return $transcript;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWithRetry(string $url, string $apiKey, array $payload, VoiceAudioBounds $bounds): Response
    {
        $timeout = $bounds->timeoutSeconds;
        $connect = $this->settings->connectTimeoutSeconds();
        $attempts = $bounds->profile === VoiceAudioBounds::TELEGRAM_VOICE ? 1 : 2;
        $response = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout($timeout)
                    ->connectTimeout($connect)
                    ->acceptJson()
                    ->withHeaders([
                        'x-goog-api-key' => $apiKey,
                    ])
                    ->post($url, $payload);
            } catch (ConnectionException) {
                if ($attempt >= $attempts) {
                    throw VoiceException::sttTimeout();
                }

                continue;
            }

            if ($response->serverError() && $attempt < $attempts) {
                continue;
            }

            return $response;
        }

        throw VoiceException::sttFailed();
    }

    private function assertBounded(VoiceAudioChunk $chunk): void
    {
        $bounds = VoiceAudioBounds::forChunk($chunk);
        $effectiveBytes = $bounds->profile === VoiceAudioBounds::TELEGRAM_VOICE
            ? $bounds->maxBytes
            : min($bounds->maxBytes, $this->settings->geminiSttMaxInlineBytes());

        if ($chunk->byteLength <= 0 || $chunk->byteLength > $effectiveBytes) {
            throw VoiceException::audioTooLarge();
        }

        if ($chunk->durationMs !== null && $chunk->durationMs > $bounds->maxDurationMs) {
            throw VoiceException::audioTooLarge();
        }
    }

    /**
     * Empty PHP arrays JSON-encode as `[]`. Gemini proto expects an object here.
     *
     * @return array<string, mixed>|\stdClass
     */
    private function audioTranscriptionConfig(?string $language): array|\stdClass
    {
        $config = $this->transcriptionConfig($language);

        return $config === [] ? new \stdClass : $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function transcriptionConfig(?string $language): array
    {
        $config = [];
        $hint = $this->optionalLanguageHint($language);

        if ($hint !== null) {
            $config['languageCodes'] = [$hint];
        }

        return $config;
    }

    private function optionalLanguageHint(?string $language): ?string
    {
        $value = trim((string) $language);

        if ($value === '') {
            return null;
        }

        if (! preg_match('/^[a-z]{2}(?:-[A-Za-z]{2})?$/', $value)) {
            return null;
        }

        return $value;
    }

    private function sanitizeModel(string $model): string
    {
        $model = ltrim($model, '/');

        if (str_starts_with($model, 'models/')) {
            $model = substr($model, 7);
        }

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $model)) {
            throw VoiceException::sttNotConfigured();
        }

        return $model;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function normalizeTranscript(array $body, string $model, ?string $languageHint): SpeechTranscript
    {
        $parts = $body['candidates'][0]['content']['parts'] ?? [];
        $chunks = [];
        $language = $this->optionalLanguageHint($languageHint);
        $confidence = null;

        if (! is_array($parts)) {
            $parts = [];
        }

        foreach ($parts as $part) {
            if (! is_array($part) || ($part['thought'] ?? false) === true) {
                continue;
            }

            if (isset($part['text']) && is_string($part['text']) && trim($part['text']) !== '') {
                $chunks[] = trim($part['text']);
            }

            $transcription = $part['audioTranscription'] ?? $part['audio_transcription'] ?? null;

            if (! is_array($transcription)) {
                continue;
            }

            foreach (['text', 'transcript'] as $key) {
                if (isset($transcription[$key]) && is_string($transcription[$key]) && trim($transcription[$key]) !== '') {
                    $chunks[] = trim($transcription[$key]);
                }
            }

            $detected = $transcription['languageCode'] ?? $transcription['language_code'] ?? $transcription['language'] ?? null;

            if (is_string($detected) && $this->optionalLanguageHint($detected) !== null) {
                $language = $this->optionalLanguageHint($detected);
            }

            if (isset($transcription['confidence']) && is_numeric($transcription['confidence'])) {
                $confidence = (float) $transcription['confidence'];
            }
        }

        return new SpeechTranscript(
            text: trim(implode(' ', $chunks)),
            isFinal: true,
            language: $language,
            confidence: $confidence,
            providerMetadata: [
                'provider' => $this->name(),
                'model' => $model,
            ],
        );
    }

    private function looksLikeUnsupportedMedia(Response $response): bool
    {
        if ($response->status() === 415) {
            return true;
        }

        $message = strtolower((string) $response->json('error.message', ''));

        if ($message === '' || str_contains($message, 'unknown name') || str_contains($message, 'json payload')) {
            return false;
        }

        $code = strtolower((string) $response->json('error.status', ''));

        return $code === 'invalid_argument' && (
            str_contains($message, 'mime')
            || str_contains($message, 'unsupported media')
            || str_contains($message, 'audio format')
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function logOutcome(VoiceAudioChunk $chunk, string $mime, string $model, float $started, string $code, array $extra = []): void
    {
        $this->metrics->record('stt.provider', array_merge([
            'session_public_id' => $chunk->sessionPublicId,
            'provider' => $this->name(),
            'model' => $model,
            'mime' => $mime,
            'audio_bytes' => $chunk->byteLength,
            'duration_ms' => $chunk->durationMs,
            'timeout_seconds' => VoiceAudioBounds::forChunk($chunk)->timeoutSeconds,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'result' => $code,
        ], $extra));
    }
}
