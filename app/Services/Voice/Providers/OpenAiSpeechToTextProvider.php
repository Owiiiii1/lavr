<?php

namespace App\Services\Voice\Providers;

use App\Services\Voice\Contracts\SpeechToTextProvider;
use App\Services\Voice\DTO\SpeechTranscript;
use App\Services\Voice\DTO\VoiceAudioChunk;
use App\Services\Voice\Exceptions\VoiceException;
use App\Services\Voice\VoiceAudioBounds;
use App\Services\Voice\VoiceAudioMime;
use App\Services\Voice\VoiceSettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class OpenAiSpeechToTextProvider implements SpeechToTextProvider
{
    public function __construct(
        private readonly VoiceSettingsService $settings,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return $this->settings->openaiConfigured();
    }

    public function transcribe(VoiceAudioChunk $chunk, ?string $language = null): SpeechTranscript
    {
        $apiKey = $this->settings->openaiApiKey();

        if ($apiKey === '') {
            throw VoiceException::sttNotConfigured();
        }

        $timeout = VoiceAudioBounds::forChunk($chunk)->timeoutSeconds;
        $connect = max(1, (int) config('voice.connect_timeout_seconds', 5));
        $base = rtrim((string) config('voice.openai_stt.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('voice.openai_stt.model', 'whisper-1');

        try {
            $pending = Http::timeout($timeout)
                ->connectTimeout($connect)
                ->withToken($apiKey)
                ->attach('file', (string) file_get_contents($chunk->absolutePath), 'utterance'.$this->extension($chunk->mime));

            $form = [
                'model' => $model,
                'response_format' => 'json',
            ];

            if (filled($language)) {
                $form['language'] = $language;
            }

            $response = $pending->post($base.'/audio/transcriptions', $form);
        } catch (ConnectionException) {
            throw VoiceException::sttFailed();
        } catch (Throwable) {
            throw VoiceException::sttFailed();
        }

        if (! $response->successful()) {
            throw VoiceException::sttFailed();
        }

        $text = trim((string) $response->json('text', ''));

        if ($text === '') {
            throw VoiceException::sttFailed();
        }

        return new SpeechTranscript(
            text: $text,
            isFinal: true,
            language: $language,
            confidence: null,
            providerMetadata: [
                'provider' => $this->name(),
                'model' => $model,
            ],
        );
    }

    private function extension(string $mime): string
    {
        return VoiceAudioMime::dottedExtension($mime);
    }
}
