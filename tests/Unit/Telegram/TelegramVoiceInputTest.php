<?php

namespace Tests\Unit\Telegram;

use App\Enums\MessageType;
use App\Enums\TelegramResponseMode;
use App\Enums\UserStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Conversations\ChannelContext;
use App\Services\Conversations\ConversationTurnResult;
use App\Services\Groups\TelegramGroupMessageMapper;
use App\Services\Telegram\Contracts\CompletesTelegramUserTurn;
use App\Services\Telegram\Contracts\DownloadsTelegramVoice;
use App\Services\Telegram\Contracts\LooksUpTelegramInbound;
use App\Services\Telegram\DTO\TelegramDownloadedVoiceFile;
use App\Services\Telegram\DTO\TelegramExistingInbound;
use App\Services\Telegram\DTO\TelegramVoiceNote;
use App\Services\Telegram\TelegramConversationMessages;
use App\Services\Telegram\TelegramVoiceDeliveryDecision;
use App\Services\Telegram\TelegramVoiceInboundService;
use App\Services\Telegram\TelegramVoiceInboundStatus;
use App\Services\Voice\Contracts\RecordsVoiceMetrics;
use App\Services\Voice\Contracts\StoresEphemeralVoiceAudio;
use App\Services\Voice\Contracts\TranscribesSpeech;
use App\Services\Voice\DTO\SpeechTranscript;
use App\Services\Voice\DTO\VoiceAudioChunk;
use App\Services\Voice\Exceptions\VoiceException;
use App\Services\Voice\VoiceAudioBounds;
use App\Services\Voice\VoiceAudioMime;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SergiX44\Nutgram\Telegram\Types\Media\Voice;
use SergiX44\Nutgram\Telegram\Types\Message\Message as TelegramMessage;

class TelegramVoiceInputTest extends TestCase
{
    public function test_voice_dm_downloads_and_transcribes_once_then_completes_turn(): void
    {
        $stt = new FakeTranscriber(new SpeechTranscript('Назови число сорок два.', true));
        $turns = new FakeTurnRunner;
        $store = new RecordingTempStore;
        $downloader = new FakeVoiceDownloader('ogg-bytes');

        $result = $this->service($stt, $turns, $store)->handle(
            $this->user(),
            $this->conversation(),
            $this->note(),
            $downloader,
        );

        $this->assertSame(TelegramVoiceInboundStatus::Turn, $result->status);
        $this->assertSame(1, $downloader->calls);
        $this->assertSame(1, $stt->calls);
        $this->assertSame(['Назови число сорок два.'], $turns->texts);
        $this->assertSame('voice', $turns->channels[0]->inboundModality);
        $this->assertSame('voice', $turns->channels[0]->metadata['modality']);
        $this->assertSame('telegram', $turns->channels[0]->metadata['source']);
        $this->assertSame(1, $store->deletes);
        $this->assertSame([], $store->remaining());
    }

    public function test_duplicate_message_skips_download_and_stt(): void
    {
        $stt = new FakeTranscriber(new SpeechTranscript('again', true));
        $turns = new FakeTurnRunner;
        $downloader = new FakeVoiceDownloader('ogg-bytes');
        $lookup = new FakeInboundLookup(new TelegramExistingInbound('already', true));

        $result = $this->service($stt, $turns, new RecordingTempStore, $lookup)->handle(
            $this->user(),
            $this->conversation(),
            $this->note(),
            $downloader,
        );

        $this->assertSame(TelegramVoiceInboundStatus::Duplicate, $result->status);
        $this->assertSame(0, $downloader->calls);
        $this->assertSame(0, $stt->calls);
        $this->assertSame([], $turns->texts);
    }

    public function test_empty_transcript_does_not_start_a_turn(): void
    {
        $stt = new FakeTranscriber(new SpeechTranscript("  \n", true));
        $turns = new FakeTurnRunner;
        $downloader = new FakeVoiceDownloader('ogg-bytes');

        $result = $this->service($stt, $turns)->handle(
            $this->user(),
            $this->conversation(),
            $this->note(),
            $downloader,
        );

        $this->assertSame(TelegramVoiceInboundStatus::UserNotice, $result->status);
        $this->assertSame(TelegramConversationMessages::VOICE_EMPTY, $result->userText);
        $this->assertSame(0, count($turns->texts));
        $this->assertSame(1, $downloader->calls);
        $this->assertSame(1, $stt->calls);
    }

    public function test_stt_failure_does_not_start_a_turn(): void
    {
        $stt = new FakeTranscriber(exception: VoiceException::sttFailed());
        $turns = new FakeTurnRunner;
        $store = new RecordingTempStore;
        $downloader = new FakeVoiceDownloader('ogg-bytes');

        $result = $this->service($stt, $turns, $store)->handle(
            $this->user(),
            $this->conversation(),
            $this->note(),
            $downloader,
        );

        $this->assertSame(TelegramVoiceInboundStatus::UserNotice, $result->status);
        $this->assertSame(TelegramConversationMessages::VOICE_STT_FAILED, $result->userText);
        $this->assertSame([], $turns->texts);
        $this->assertSame([], $store->remaining());
    }

    public function test_unsupported_mime_skips_download_and_stt(): void
    {
        $stt = new FakeTranscriber(new SpeechTranscript('nope', true));
        $turns = new FakeTurnRunner;
        $downloader = new FakeVoiceDownloader('ogg-bytes');

        $result = $this->service($stt, $turns)->handle(
            $this->user(),
            $this->conversation(),
            $this->note(mimeType: 'video/mp4'),
            $downloader,
        );

        $this->assertSame(TelegramVoiceInboundStatus::UserNotice, $result->status);
        $this->assertSame(TelegramConversationMessages::VOICE_UNSUPPORTED, $result->userText);
        $this->assertSame(0, $downloader->calls);
        $this->assertSame(0, $stt->calls);
        $this->assertSame([], $turns->texts);
    }

    public function test_short_and_long_voice_notes_are_transcribed_once(): void
    {
        foreach ([29, 31, 300, 600] as $seconds) {
            $stt = new RecordingTranscriber(new SpeechTranscript('Полный текст голосового.', true));
            $turns = new FakeTurnRunner;
            $store = new RecordingTempStore;

            $result = $this->service($stt, $turns, $store)->handle(
                $this->user(),
                $this->conversation(),
                $this->note(durationSeconds: $seconds, fileSize: 3_000_000),
                new FakeVoiceDownloader('ogg-bytes'),
            );

            $this->assertSame(TelegramVoiceInboundStatus::Turn, $result->status, (string) $seconds);
            $this->assertSame(['Полный текст голосового.'], $turns->texts);
            $this->assertSame(VoiceAudioBounds::TELEGRAM_VOICE, $stt->chunks[0]->profile);
            $this->assertSame($seconds * 1000, $stt->chunks[0]->durationMs);
            $this->assertSame([], $store->remaining());
        }
    }

    public function test_voice_over_the_configured_maximum_is_rejected_without_download(): void
    {
        $stt = new FakeTranscriber(new SpeechTranscript('nope', true));
        $turns = new FakeTurnRunner;
        $downloader = new FakeVoiceDownloader('ogg-bytes');

        $tooLong = $this->service($stt, $turns)->handle(
            $this->user(),
            $this->conversation(),
            $this->note(durationSeconds: 601),
            $downloader,
        );

        $this->assertSame('too_long', $tooLong->reason);
        $this->assertSame(
            'Голосовое слишком длинное. Максимальная длительность — 10 минут.',
            $tooLong->userText,
        );
        $this->assertSame(0, $downloader->calls);
        $this->assertSame(0, $stt->calls);
        $this->assertSame([], $turns->texts);
    }

    public function test_file_above_the_old_two_megabyte_cap_is_accepted_until_the_telegram_cap(): void
    {
        $stt = new RecordingTranscriber(new SpeechTranscript('Длинный файл.', true));
        $turns = new FakeTurnRunner;
        $bytes = str_repeat('a', 2_000_001);
        $downloader = new FakeVoiceDownloader($bytes);

        $accepted = $this->service($stt, $turns)->handle(
            $this->user(),
            $this->conversation(),
            $this->note(durationSeconds: 300, fileSize: 2_000_001),
            $downloader,
        );
        $rejectedDownloader = new FakeVoiceDownloader($bytes);
        $rejected = $this->service(new FakeTranscriber(new SpeechTranscript('nope', true)), new FakeTurnRunner)->handle(
            $this->user(),
            $this->conversation(),
            $this->note(fileSize: 20_000_001),
            $rejectedDownloader,
        );

        $this->assertSame(TelegramVoiceInboundStatus::Turn, $accepted->status);
        $this->assertSame(2_000_001, $stt->chunks[0]->byteLength);
        $this->assertSame('too_large', $rejected->reason);
        $this->assertSame(1, $downloader->calls);
        $this->assertSame(0, $rejectedDownloader->calls);
    }

    public function test_downloaded_and_actual_bytes_over_the_cap_skip_transcription(): void
    {
        $stt = new FakeTranscriber(new SpeechTranscript('nope', true));
        $turns = new FakeTurnRunner;
        $store = new RecordingTempStore;
        $reported = new FakeVoiceDownloader('abc', reportedBytes: 3_000);
        $actual = new FakeVoiceDownloader(str_repeat('b', 2_500), reportedBytes: 500);

        $byReport = $this->service($stt, $turns, $store, maxBytes: 2_000)->handle(
            $this->user(),
            $this->conversation(),
            $this->note(fileSize: null),
            $reported,
        );
        $byContents = $this->service($stt, $turns, $store, maxBytes: 2_000)->handle(
            $this->user(),
            $this->conversation(),
            $this->note(fileSize: null),
            $actual,
        );

        $this->assertSame('too_large', $byReport->reason);
        $this->assertSame('too_large', $byContents->reason);
        $this->assertSame(0, $stt->calls);
        $this->assertSame([], $store->remaining());
    }

    public function test_temp_file_is_deleted_after_success_and_failure(): void
    {
        $successStore = new RecordingTempStore;
        $failureStore = new RecordingTempStore;
        $success = new FakeTranscriber(new SpeechTranscript('ok', true));
        $failure = new FakeTranscriber(exception: VoiceException::sttTimeout());

        $this->service($success, new FakeTurnRunner, $successStore)->handle(
            $this->user(),
            $this->conversation(),
            $this->note(),
            new FakeVoiceDownloader('ogg-bytes'),
        );
        $this->service($failure, new FakeTurnRunner, $failureStore)->handle(
            $this->user(),
            $this->conversation(),
            $this->note(),
            new FakeVoiceDownloader('ogg-bytes'),
        );

        $this->assertSame([], $successStore->remaining());
        $this->assertSame([], $failureStore->remaining());
        $this->assertGreaterThan(0, $successStore->deletes);
        $this->assertGreaterThan(0, $failureStore->deletes);
    }

    public function test_response_mode_auto_uses_inbound_modality(): void
    {
        $this->assertFalse(TelegramVoiceDeliveryDecision::shouldAttemptVoice(
            TelegramResponseMode::Text,
            'voice',
        ));
        $this->assertTrue(TelegramVoiceDeliveryDecision::shouldAttemptVoice(
            TelegramResponseMode::Voice,
            'voice',
        ));
        $this->assertTrue(TelegramVoiceDeliveryDecision::shouldAttemptVoice(
            TelegramResponseMode::Auto,
            'voice',
        ));
        $this->assertFalse(TelegramVoiceDeliveryDecision::shouldAttemptVoice(
            TelegramResponseMode::Auto,
            'text',
        ));
    }

    public function test_group_voice_mapper_keeps_placeholder_without_transcript(): void
    {
        $message = new TelegramMessage;
        $message->message_id = 99;
        $message->date = 1_700_000_000;
        $voice = new Voice;
        $voice->file_id = 'file-group';
        $voice->file_unique_id = 'unique-group';
        $voice->duration = 4;
        $voice->mime_type = 'audio/ogg';
        $message->voice = $voice;

        $mapped = (new TelegramGroupMessageMapper)->map($message);

        $this->assertSame(MessageType::Voice, $mapped['message_type']);
        $this->assertSame('[voice]', $mapped['body']);
    }

    public function test_disabled_user_skips_stt_and_ai(): void
    {
        $stt = new FakeTranscriber(new SpeechTranscript('nope', true));
        $turns = new FakeTurnRunner;
        $downloader = new FakeVoiceDownloader('ogg-bytes');
        $user = $this->user();
        $user->status = UserStatus::Disabled;

        $result = $this->service($stt, $turns)->handle(
            $user,
            $this->conversation(),
            $this->note(),
            $downloader,
        );

        $this->assertSame(TelegramVoiceInboundStatus::Ignored, $result->status);
        $this->assertSame(0, $downloader->calls);
        $this->assertSame(0, $stt->calls);
        $this->assertSame([], $turns->texts);
    }

    public function test_opus_alias_is_treated_as_ogg(): void
    {
        $this->assertSame('audio/ogg', VoiceAudioMime::canonicalize('audio/ogg; codecs=opus'));
        $this->assertSame('audio/ogg', VoiceAudioMime::canonicalize('audio/opus'));
        $this->assertSame('audio/ogg', VoiceAudioMime::canonicalize('application/ogg'));
    }

    private function service(
        TranscribesSpeech $stt,
        CompletesTelegramUserTurn $turns,
        ?RecordingTempStore $store = null,
        ?LooksUpTelegramInbound $lookup = null,
        int $maxBytes = 20_000_000,
        int $maxSeconds = 600,
    ): TelegramVoiceInboundService {
        return new TelegramVoiceInboundService(
            $lookup ?? new FakeInboundLookup(null),
            $stt,
            $store ?? new RecordingTempStore,
            $turns,
            new class implements RecordsVoiceMetrics
            {
                public function record(string $event, array $context = []): void {}
            },
            $maxBytes,
            $maxSeconds,
            20_000_000,
        );
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 9;
        $user->status = UserStatus::Active;

        return $user;
    }

    private function conversation(): Conversation
    {
        $conversation = new Conversation;
        $conversation->id = 12;

        return $conversation;
    }

    private function note(
        int $durationSeconds = 2,
        ?int $fileSize = 1200,
        ?string $mimeType = 'audio/ogg',
    ): TelegramVoiceNote {
        return new TelegramVoiceNote(
            fileId: 'file-1',
            channelMessageId: '88',
            durationSeconds: $durationSeconds,
            fileUniqueId: 'unique-1',
            mimeType: $mimeType,
            fileSize: $fileSize,
        );
    }
}

class FakeTranscriber implements TranscribesSpeech
{
    public int $calls = 0;

    public function __construct(
        private readonly ?SpeechTranscript $transcript = null,
        private readonly ?VoiceException $exception = null,
        private readonly bool $configured = true,
    ) {}

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function transcribe(VoiceAudioChunk $chunk, ?string $language = null): SpeechTranscript
    {
        $this->calls++;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->transcript ?? new SpeechTranscript('', true);
    }

    public function supportedInputMimes(): array
    {
        return ['audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/webm', 'audio/wav', 'audio/flac', 'audio/aac'];
    }
}

final class RecordingTranscriber extends FakeTranscriber
{
    /** @var list<VoiceAudioChunk> */
    public array $chunks = [];

    public function transcribe(VoiceAudioChunk $chunk, ?string $language = null): SpeechTranscript
    {
        $this->chunks[] = $chunk;

        return parent::transcribe($chunk, $language);
    }
}

final class FakeTurnRunner implements CompletesTelegramUserTurn
{
    /** @var list<string> */
    public array $texts = [];

    /** @var list<ChannelContext> */
    public array $channels = [];

    public function complete(
        User $user,
        Conversation $conversation,
        string $text,
        ChannelContext $channel,
    ): ConversationTurnResult {
        $this->texts[] = $text;
        $this->channels[] = $channel;
        $assistant = new Message;
        $assistant->forceFill(['body' => 'assistant-reply']);

        return new ConversationTurnResult(new Message, true, $assistant);
    }
}

final class FakeInboundLookup implements LooksUpTelegramInbound
{
    public function __construct(
        private readonly ?TelegramExistingInbound $existing,
    ) {}

    public function find(int $conversationId, string $channelMessageId): ?TelegramExistingInbound
    {
        return $this->existing;
    }
}

final class FakeVoiceDownloader implements DownloadsTelegramVoice
{
    public int $calls = 0;

    public function __construct(
        private readonly string $bytes,
        private readonly bool $fail = false,
        private readonly ?int $reportedBytes = null,
    ) {}

    public function download(string $fileId, string $absolutePath): TelegramDownloadedVoiceFile
    {
        $this->calls++;

        if ($this->fail) {
            throw new RuntimeException('download failed');
        }

        $directory = dirname($absolutePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($absolutePath, $this->bytes);

        $length = $this->reportedBytes ?? strlen($this->bytes);

        return new TelegramDownloadedVoiceFile($absolutePath, $length, $length);
    }
}

final class RecordingTempStore implements StoresEphemeralVoiceAudio
{
    /** @var array<string, string> */
    public array $files = [];

    public int $deletes = 0;

    public function putBytes(string $relativePath, string $bytes): string
    {
        $path = sys_get_temp_dir().'/jarvis-tg-test-'.bin2hex(random_bytes(8));
        file_put_contents($path, $bytes);
        $this->files[$relativePath] = $path;

        return $relativePath;
    }

    public function absolutePath(string $relativePath): string
    {
        return $this->files[$relativePath] ?? sys_get_temp_dir().'/missing-'.$relativePath;
    }

    public function deleteRelative(string $relativePath): void
    {
        $this->deletes++;
        $absolute = $this->files[$relativePath] ?? null;

        if (is_string($absolute) && is_file($absolute)) {
            @unlink($absolute);
        }

        unset($this->files[$relativePath]);
    }

    /**
     * @return list<string>
     */
    public function remaining(): array
    {
        return array_values(array_filter(
            $this->files,
            static fn (string $path): bool => is_file($path),
        ));
    }
}
