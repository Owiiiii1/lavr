<?php

namespace Tests\Feature;

use App\Enums\IntegrationAccountStatus;
use App\Enums\MeetingSourceType;
use App\Enums\UserRole;
use App\Enums\ZoomWebhookEventStatus;
use App\Jobs\AnalyzeMeetingTranscriptJob;
use App\Jobs\ProcessZoomTranscriptJob;
use App\Models\Meeting;
use App\Models\MeetingArtifact;
use App\Models\Person;
use App\Models\User;
use App\Models\ZoomWebhookEvent;
use App\Services\Meetings\MeetingService;
use App\Services\Projects\ProjectService;
use App\Services\Zoom\Exceptions\ZoomException;
use App\Services\Zoom\ZoomCredentialService;
use App\Services\Zoom\ZoomMeetingIngestor;
use App\Services\Zoom\ZoomTranscriptClient;
use App\Services\Zoom\ZoomWebhookService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class ZoomTranscriptIngestTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_transcript_download_creates_meeting_artifact_and_dispatches_analyzer(): void
    {
        $owner = null;

        try {
            Bus::fake([AnalyzeMeetingTranscriptJob::class]);
            $owner = $this->zoomOwner();
            $this->saveZoom($owner);
            $this->fakeZoomHttp($this->vtt());
            $event = $this->storeEvent('uuid-instance-a', 555);

            (new ProcessZoomTranscriptJob($event->id))->handle(app(ZoomMeetingIngestor::class));

            $meeting = Meeting::query()->where('user_id', $owner->id)->where('source_type', MeetingSourceType::Zoom)->first();
            $this->assertNotNull($meeting);
            $this->assertSame('uuid-instance-a', $meeting->source_external_id);
            $this->assertNull($meeting->project_id);
            $artifact = $meeting->artifacts()->first();
            $this->assertNotNull($artifact);
            $this->assertSame('vtt', $artifact->extension);
            $this->assertTrue(Storage::disk((string) config('meetings.disk', 'local'))->exists($artifact->storage_path));
            $this->assertStringStartsWith('meetings/'.$owner->id.'/', $artifact->storage_path);
            Bus::assertDispatched(AnalyzeMeetingTranscriptJob::class);
            Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer zoom-access-token'));
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_untrusted_download_host_is_rejected(): void
    {
        $owner = null;

        try {
            $owner = $this->zoomOwner();
            $this->saveZoom($owner);
            Http::preventStrayRequests();
            Http::fake([
                'https://zoom.us/oauth/token' => Http::response(['access_token' => 'zoom-access-token', 'expires_in' => 3600], 200),
                'https://api.zoom.us/v2/meetings/*' => Http::response([
                    'can_download' => true,
                    'download_url' => 'https://evil.example/transcript.vtt',
                    'meeting_topic' => 'Bad',
                ], 200),
            ]);
            $event = $this->storeEvent('uuid-evil', 1);

            try {
                app(ZoomMeetingIngestor::class)->ingest($event);
                $this->fail('Expected ZoomException');
            } catch (ZoomException $exception) {
                $this->assertSame('untrusted_download', $exception->error);
            }
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_trusted_host_is_accepted_and_untrusted_client_check_fails(): void
    {
        $client = app(ZoomTranscriptClient::class);
        $client->assertTrustedDownloadUrl('https://file.zoom.us/rec/meeting/transcript/download/abc');

        try {
            $client->assertTrustedDownloadUrl('https://example.com/file.vtt');
            $this->fail('Expected ZoomException');
        } catch (ZoomException $exception) {
            $this->assertSame('untrusted_download', $exception->error);
        }
    }

    public function test_timeout_is_retryable(): void
    {
        Http::preventStrayRequests();
        Http::fake(function () {
            throw new ConnectionException('timeout');
        });

        try {
            app(ZoomTranscriptClient::class)->fetchTranscriptMetadata('token', 'uuid');
            $this->fail('Expected ZoomException');
        } catch (ZoomException $exception) {
            $this->assertSame('network', $exception->error);
            $this->assertTrue($exception->retryable);
        }
    }

    public function test_404_and_429_are_retryable(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.zoom.us/v2/meetings/*/transcript' => Http::sequence()
                ->push([], 404)
                ->push([], 429, ['Retry-After' => '17']),
        ]);

        try {
            app(ZoomTranscriptClient::class)->fetchTranscriptMetadata('token', 'uuid-404');
            $this->fail('Expected 404');
        } catch (ZoomException $exception) {
            $this->assertSame('transcript_unavailable', $exception->error);
            $this->assertTrue($exception->retryable);
        }

        try {
            app(ZoomTranscriptClient::class)->fetchTranscriptMetadata('token', 'uuid-429');
            $this->fail('Expected 429');
        } catch (ZoomException $exception) {
            $this->assertSame('rate_limited', $exception->error);
            $this->assertTrue($exception->retryable);
            $this->assertSame(17, $exception->retryAfterSeconds);
        }
    }

    public function test_duplicate_checksum_and_meeting_uuid_dedupe(): void
    {
        $owner = null;

        try {
            Bus::fake([AnalyzeMeetingTranscriptJob::class]);
            $owner = $this->zoomOwner();
            $this->saveZoom($owner);
            $this->fakeZoomHttp($this->vtt());
            $first = $this->storeEvent('uuid-same', 9001);
            $second = $this->storeEvent('uuid-same', 9001, 'file-uuid-same-2');

            app(ZoomMeetingIngestor::class)->ingest($first);
            app(ZoomMeetingIngestor::class)->ingest($second);

            $this->assertSame(1, Meeting::query()->where('user_id', $owner->id)->where('source_type', MeetingSourceType::Zoom)->count());
            $meeting = Meeting::query()->where('user_id', $owner->id)->first();
            $this->assertSame(1, MeetingArtifact::query()->where('meeting_id', $meeting->id)->count());
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_recurring_meetings_use_uuid_not_numeric_id(): void
    {
        $owner = null;

        try {
            Bus::fake([AnalyzeMeetingTranscriptJob::class]);
            $owner = $this->zoomOwner();
            $this->saveZoom($owner);
            $this->fakeZoomHttp($this->vtt("WEBVTT\n\n1\n00:00:00.000 --> 00:00:01.000\nA: one\n"));
            app(ZoomMeetingIngestor::class)->ingest($this->storeEvent('uuid-recurring-1', 42));
            $this->fakeZoomHttp($this->vtt("WEBVTT\n\n1\n00:00:00.000 --> 00:00:01.000\nB: two\n"));
            app(ZoomMeetingIngestor::class)->ingest($this->storeEvent('uuid-recurring-2', 42));

            $meetings = Meeting::query()->where('user_id', $owner->id)->where('source_type', MeetingSourceType::Zoom)->orderBy('id')->get();
            $this->assertCount(2, $meetings);
            $this->assertSame('uuid-recurring-1', $meetings[0]->source_external_id);
            $this->assertSame('uuid-recurring-2', $meetings[1]->source_external_id);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_does_not_overwrite_project_notes_or_create_people(): void
    {
        $owner = null;

        try {
            Bus::fake([AnalyzeMeetingTranscriptJob::class]);
            $owner = $this->zoomOwner();
            $this->saveZoom($owner);
            $project = app(ProjectService::class)->create($owner, 'Kept Project');
            $meeting = Meeting::query()->create([
                'user_id' => $owner->id,
                'project_id' => $project->id,
                'title' => 'Owner title',
                'notes' => 'Keep these notes',
                'source_type' => MeetingSourceType::Zoom,
                'source_external_id' => 'uuid-keep',
                'status' => 'ready',
                'analysis_status' => 'pending',
            ]);
            $this->fakeZoomHttp($this->vtt());
            app(ZoomMeetingIngestor::class)->ingest($this->storeEvent('uuid-keep', 77));

            $meeting->refresh();
            $this->assertSame($project->id, $meeting->project_id);
            $this->assertSame('Keep these notes', $meeting->notes);
            $this->assertSame('Owner title', $meeting->title);
            $this->assertSame(0, Person::query()->where('user_id', $owner->id)->count());
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_manual_paste_still_works(): void
    {
        $owner = null;

        try {
            Bus::fake([AnalyzeMeetingTranscriptJob::class]);
            $owner = $this->zoomOwner();
            $meeting = app(MeetingService::class)->createManual($owner, ['title' => 'Manual'], null, "Speaker: hello\n");
            $this->assertSame(MeetingSourceType::ManualText, $meeting->source_type);
            $this->assertNotNull($meeting->artifacts()->first());
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    private function zoomOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }

    private function saveZoom(User $owner): void
    {
        $account = app(ZoomCredentialService::class)->save($owner, [
            'account_id' => 'configured-account',
            'client_id' => 'zoom-client-id',
            'client_secret' => 'zoom-client-secret',
            'webhook_secret' => 'zoom-webhook-secret',
            'enabled' => true,
        ]);
        $account->forceFill(['status' => IntegrationAccountStatus::Connected])->save();
    }

    private function fakeZoomHttp(string $vtt): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://zoom.us/oauth/token' => Http::response([
                'access_token' => 'zoom-access-token',
                'expires_in' => 3600,
            ], 200),
            'https://api.zoom.us/v2/meetings/*' => Http::response([
                'can_download' => true,
                'download_url' => 'https://file.zoom.us/rec/meeting/transcript/download/abc',
                'meeting_topic' => 'Weekly sync',
            ], 200),
            'https://file.zoom.us/*' => Http::response($vtt, 200, ['Content-Type' => 'text/vtt']),
        ]);
    }

    private function storeEvent(string $uuid, int $numericId, ?string $recordingId = null): ZoomWebhookEvent
    {
        $payload = [
            'event' => 'recording.transcript_completed',
            'event_ts' => 1710000000000 + crc32($uuid.($recordingId ?? '')),
            'payload' => [
                'account_id' => 'configured-account',
                'object' => [
                    'id' => $numericId,
                    'uuid' => $uuid,
                    'topic' => 'Weekly sync',
                    'start_time' => '2026-09-10T08:00:00Z',
                    'timezone' => 'UTC',
                    'duration' => 30,
                    'host_id' => 'host-1',
                    'host_email' => 'host@example.test',
                    'recording_files' => [
                        [
                            'id' => $recordingId ?? ('file-'.$uuid),
                            'file_type' => 'TRANSCRIPT',
                            'recording_type' => 'audio_transcript',
                        ],
                    ],
                ],
            ],
        ];

        $event = app(ZoomWebhookService::class)->persist($payload);
        $event->forceFill(['status' => ZoomWebhookEventStatus::Dispatched])->save();

        return $event;
    }

    private function vtt(string $override = ''): string
    {
        if ($override !== '') {
            return $override;
        }

        return (string) file_get_contents(base_path('tests/Fixtures/meetings/synthetic.vtt'));
    }
}
