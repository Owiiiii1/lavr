<?php

namespace App\Services\Telegram;

use App\Enums\MessageChannel;
use App\Enums\UserStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ChannelContext;
use App\Services\Telegram\Contracts\CompletesTelegramUserTurn;
use App\Services\Telegram\Contracts\DownloadsTelegramVoice;
use App\Services\Telegram\Contracts\LooksUpTelegramInbound;
use App\Services\Telegram\DTO\TelegramExistingInbound;
use App\Services\Telegram\DTO\TelegramVoiceNote;
use App\Services\Voice\Contracts\RecordsVoiceMetrics;
use App\Services\Voice\Contracts\StoresEphemeralVoiceAudio;
use App\Services\Voice\Contracts\TranscribesSpeech;
use App\Services\Voice\DTO\VoiceAudioChunk;
use App\Services\Voice\Exceptions\VoiceException;
use App\Services\Voice\VoiceAudioBounds;
use App\Services\Voice\VoiceAudioMime;
use DateTimeImmutable;
use Throwable;

final class TelegramVoiceInboundService
{
    public function __construct(
        private readonly LooksUpTelegramInbound $lookup,
        private readonly TranscribesSpeech $stt,
        private readonly StoresEphemeralVoiceAudio $tempAudio,
        private readonly CompletesTelegramUserTurn $turns,
        private readonly RecordsVoiceMetrics $metrics,
        private readonly int $maxInboundBytes = 20_000_000,
        private readonly int $maxInboundSeconds = 600,
        private readonly int $apiDownloadMaxBytes = 20_000_000,
    ) {}

    public function handle(
        User $user,
        Conversation $conversation,
        TelegramVoiceNote $note,
        DownloadsTelegramVoice $downloader,
    ): TelegramVoiceInboundResult {
        if ($user->status !== UserStatus::Active) {
            $this->record('inbound.ignored', $user, 'disabled_user');

            return TelegramVoiceInboundResult::ignored('disabled_user');
        }

        $existing = $this->lookup->find((int) $conversation->id, $note->channelMessageId);

        if ($existing !== null) {
            return $this->resumeExisting($user, $conversation, $note, $existing);
        }

        if (! $this->stt->isConfigured()) {
            return $this->notice($user, TelegramConversationMessages::VOICE_STT_UNAVAILABLE, 'stt_not_configured');
        }

        $maxBytes = max(1024, min($this->maxInboundBytes, $this->apiDownloadMaxBytes));
        $maxSeconds = max(1, $this->maxInboundSeconds);

        if ($note->durationSeconds > $maxSeconds) {
            return $this->notice($user, TelegramConversationMessages::voiceTooLong($maxSeconds), 'too_long');
        }

        if ($note->fileSize !== null && $note->fileSize > $maxBytes) {
            return $this->notice($user, TelegramConversationMessages::VOICE_TOO_LARGE, 'too_large');
        }

        $supported = $this->stt->supportedInputMimes();
        $declared = VoiceAudioMime::canonicalize((string) $note->mimeType);

        if ($declared !== '' && ! in_array($declared, $supported, true) && ! $this->declaredMimeIsGeneric($declared)) {
            return $this->notice($user, TelegramConversationMessages::VOICE_UNSUPPORTED, 'unsupported_mime');
        }

        $tmp = null;
        $relative = null;

        try {
            $tmp = tempnam(sys_get_temp_dir(), 'jarvis-tg-in-');

            if ($tmp === false) {
                return $this->notice($user, TelegramConversationMessages::VOICE_DOWNLOAD_FAILED, 'temp_failed');
            }

            try {
                $downloaded = $downloader->download($note->fileId, $tmp);
            } catch (Throwable) {
                return $this->notice($user, TelegramConversationMessages::VOICE_DOWNLOAD_FAILED, 'download_failed');
            }

            if ($downloaded->byteLength > $maxBytes) {
                return $this->notice($user, TelegramConversationMessages::VOICE_TOO_LARGE, 'too_large');
            }

            $resolved = VoiceAudioMime::resolveTelegramVoice($note->mimeType, $tmp, $supported);

            if (! $resolved['allowed']) {
                return $this->notice($user, TelegramConversationMessages::VOICE_UNSUPPORTED, 'unsupported_mime');
            }

            $bytes = (string) file_get_contents($tmp);

            if ($bytes === '' || strlen($bytes) > $maxBytes) {
                return $this->notice(
                    $user,
                    $bytes === ''
                        ? TelegramConversationMessages::VOICE_DOWNLOAD_FAILED
                        : TelegramConversationMessages::VOICE_TOO_LARGE,
                    $bytes === '' ? 'download_failed' : 'too_large',
                );
            }

            $relative = $this->tempAudio->putBytes(
                sprintf(
                    'telegram/inbound/%d/%s%s',
                    $user->id,
                    bin2hex(random_bytes(16)),
                    VoiceAudioMime::dottedExtension($resolved['canonical']),
                ),
                $bytes,
            );
            $absolute = $this->tempAudio->absolutePath($relative);

            $chunk = new VoiceAudioChunk(
                sessionPublicId: 'telegram-dm-'.$note->channelMessageId,
                sequence: 1,
                absolutePath: $absolute,
                byteLength: strlen($bytes),
                mime: $resolved['canonical'],
                sampleRate: null,
                channels: 1,
                isFinal: true,
                durationMs: $note->durationSeconds * 1000,
                capturedAt: $note->occurredAt ?? new DateTimeImmutable,
                profile: VoiceAudioBounds::TELEGRAM_VOICE,
            );

            try {
                $transcript = $this->stt->transcribe($chunk);
            } catch (VoiceException $exception) {
                return $this->fromVoiceException($user, $exception);
            } catch (Throwable) {
                return $this->notice($user, TelegramConversationMessages::VOICE_STT_FAILED, 'stt_failed');
            }

            $text = trim($transcript->text);

            if ($text === '') {
                return $this->notice($user, TelegramConversationMessages::VOICE_EMPTY, 'empty_transcript');
            }

            $turn = $this->turns->complete(
                $user,
                $conversation,
                $text,
                $this->channelContext($note, $resolved['canonical']),
            );

            $this->record('inbound.transcribed', $user, 'ok', [
                'duration_seconds' => $note->durationSeconds,
                'audio_bytes' => strlen($bytes),
                'mime' => $resolved['canonical'],
            ]);

            return TelegramVoiceInboundResult::turn($turn);
        } finally {
            if (is_string($tmp) && $tmp !== '' && is_file($tmp)) {
                @unlink($tmp);
            }

            if (is_string($relative) && $relative !== '') {
                $this->tempAudio->deleteRelative($relative);
            }
        }
    }

    private function resumeExisting(
        User $user,
        Conversation $conversation,
        TelegramVoiceNote $note,
        TelegramExistingInbound $existing,
    ): TelegramVoiceInboundResult {
        $this->record('inbound.duplicate', $user, $existing->hasAssistantReply ? 'duplicate' : 'duplicate_resume');

        if ($existing->hasAssistantReply || $existing->body === '') {
            return TelegramVoiceInboundResult::duplicate();
        }

        $turn = $this->turns->complete(
            $user,
            $conversation,
            $existing->body,
            $this->channelContext($note, null),
        );

        return TelegramVoiceInboundResult::turn($turn);
    }

    private function channelContext(TelegramVoiceNote $note, ?string $mime): ChannelContext
    {
        $metadata = array_filter([
            'modality' => 'voice',
            'source' => 'telegram',
            'source_mime' => $mime,
            'duration_seconds' => $note->durationSeconds,
        ], static fn ($value) => $value !== null && $value !== '');

        return new ChannelContext(
            channel: MessageChannel::Telegram,
            channelMessageId: $note->channelMessageId,
            occurredAt: $note->occurredAt,
            metadata: $metadata,
            inboundModality: 'voice',
        );
    }

    private function fromVoiceException(User $user, VoiceException $exception): TelegramVoiceInboundResult
    {
        [$text, $reason] = match ($exception->error) {
            'voice_stt_not_configured' => [TelegramConversationMessages::VOICE_STT_UNAVAILABLE, 'stt_not_configured'],
            'voice_audio_too_large' => [TelegramConversationMessages::VOICE_TOO_LARGE, 'too_large'],
            'voice_audio_format_unsupported' => [TelegramConversationMessages::VOICE_UNSUPPORTED, 'unsupported_mime'],
            'voice_stt_timeout' => [TelegramConversationMessages::VOICE_STT_FAILED, 'stt_timeout'],
            'voice_stt_rate_limited' => [TelegramConversationMessages::VOICE_STT_FAILED, 'stt_rate_limited'],
            default => [TelegramConversationMessages::VOICE_STT_FAILED, 'stt_failed'],
        };

        return $this->notice($user, $text, $reason);
    }

    private function notice(User $user, string $text, string $reason): TelegramVoiceInboundResult
    {
        $this->record('inbound.fallback', $user, $reason);

        return TelegramVoiceInboundResult::notice($text, $reason);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(string $event, User $user, string $reason, array $extra = []): void
    {
        $this->metrics->record('telegram.'.$event, array_merge([
            'user_id' => $user->id,
            'reason' => $reason,
        ], $extra));
    }

    private function declaredMimeIsGeneric(string $canonical): bool
    {
        return in_array($canonical, ['application/octet-stream', 'application/ogg', 'audio/opus'], true);
    }
}
