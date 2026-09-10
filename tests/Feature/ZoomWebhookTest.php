<?php

namespace Tests\Feature;

use App\Enums\IntegrationAccountStatus;
use App\Enums\UserRole;
use App\Jobs\ProcessZoomTranscriptJob;
use App\Models\User;
use App\Models\ZoomWebhookEvent;
use App\Services\Zoom\ZoomCredentialService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class ZoomWebhookTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_endpoint_validation_challenge_returns_hmac(): void
    {
        $owner = null;

        try {
            $owner = $this->zoomOwner();
            $secret = 'zoom-webhook-secret';
            $this->saveZoom($owner, $secret);
            $plain = 'plain-token-value';
            $body = json_encode([
                'event' => 'endpoint.url_validation',
                'payload' => ['plainToken' => $plain],
                'event_ts' => 1,
            ], JSON_THROW_ON_ERROR);

            $response = $this->call('POST', '/webhooks/zoom', [], [], [], $this->signedServer($body, $secret), $body);

            $response->assertOk();
            $response->assertExactJson([
                'plainToken' => $plain,
                'encryptedToken' => hash_hmac('sha256', $plain, $secret),
            ]);
            Http::assertNothingSent();
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_valid_signature_dispatches_job_without_sync_api(): void
    {
        $owner = null;

        try {
            Bus::fake();
            Http::preventStrayRequests();
            $owner = $this->zoomOwner();
            $secret = 'zoom-webhook-secret';
            $this->saveZoom($owner, $secret);
            $body = $this->transcriptCompletedBody('abc-uuid-1');

            $response = $this->call('POST', '/webhooks/zoom', [], [], [], $this->signedServer($body, $secret), $body);

            $response->assertNoContent();
            Bus::assertDispatched(ProcessZoomTranscriptJob::class);
            Http::assertNothingSent();
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $owner = null;

        try {
            Bus::fake();
            $owner = $this->zoomOwner();
            $this->saveZoom($owner, 'zoom-webhook-secret');
            $body = $this->transcriptCompletedBody('abc-uuid-2');
            $headers = $this->signedServer($body, 'wrong-secret');

            $response = $this->call('POST', '/webhooks/zoom', [], [], [], $headers, $body);

            $response->assertUnauthorized();
            Bus::assertNotDispatched(ProcessZoomTranscriptJob::class);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_expired_timestamp_is_rejected(): void
    {
        $owner = null;

        try {
            Bus::fake();
            $owner = $this->zoomOwner();
            $secret = 'zoom-webhook-secret';
            $this->saveZoom($owner, $secret);
            $body = $this->transcriptCompletedBody('abc-uuid-3');
            $headers = $this->signedServer($body, $secret, time() - 3600);

            $response = $this->call('POST', '/webhooks/zoom', [], [], [], $headers, $body);

            $response->assertUnauthorized();
            Bus::assertNotDispatched(ProcessZoomTranscriptJob::class);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_wrong_account_is_rejected(): void
    {
        $owner = null;

        try {
            Bus::fake();
            $owner = $this->zoomOwner();
            $secret = 'zoom-webhook-secret';
            $this->saveZoom($owner, $secret, 'configured-account');
            $body = $this->transcriptCompletedBody('abc-uuid-4', 'other-account');
            $headers = $this->signedServer($body, $secret);

            $response = $this->call('POST', '/webhooks/zoom', [], [], [], $headers, $body);

            $response->assertUnauthorized();
            Bus::assertNotDispatched(ProcessZoomTranscriptJob::class);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_duplicate_webhook_is_idempotent(): void
    {
        $owner = null;

        try {
            Bus::fake();
            $owner = $this->zoomOwner();
            $secret = 'zoom-webhook-secret';
            $this->saveZoom($owner, $secret);
            $body = $this->transcriptCompletedBody('abc-uuid-5');
            $headers = $this->signedServer($body, $secret);

            $this->call('POST', '/webhooks/zoom', [], [], [], $headers, $body)->assertNoContent();
            $this->call('POST', '/webhooks/zoom', [], [], [], $headers, $body)->assertNoContent();

            Bus::assertDispatchedTimes(ProcessZoomTranscriptJob::class, 1);
            $this->assertSame(1, ZoomWebhookEvent::query()->where('meeting_uuid', 'abc-uuid-5')->count());
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_recording_completed_is_not_transcript_trigger(): void
    {
        $owner = null;

        try {
            Bus::fake();
            $owner = $this->zoomOwner();
            $secret = 'zoom-webhook-secret';
            $this->saveZoom($owner, $secret);
            $payload = json_decode($this->transcriptCompletedBody('abc-uuid-6'), true);
            $payload['event'] = 'recording.completed';
            $body = json_encode($payload, JSON_THROW_ON_ERROR);

            $this->call('POST', '/webhooks/zoom', [], [], [], $this->signedServer($body, $secret), $body)->assertNoContent();
            Bus::assertNotDispatched(ProcessZoomTranscriptJob::class);
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

    private function saveZoom(User $owner, string $secret, string $accountId = 'configured-account'): void
    {
        $account = app(ZoomCredentialService::class)->save($owner, [
            'account_id' => $accountId,
            'client_id' => 'zoom-client-id',
            'client_secret' => 'zoom-client-secret',
            'webhook_secret' => $secret,
            'enabled' => true,
        ]);
        $account->forceFill(['status' => IntegrationAccountStatus::Connected])->save();
    }

    /**
     * @return array<string, string>
     */
    private function signedServer(string $body, string $secret, ?int $timestamp = null): array
    {
        $timestamp ??= time();
        $signature = 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$body, $secret);

        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ZM_REQUEST_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_ZM_SIGNATURE' => $signature,
        ];
    }

    private function transcriptCompletedBody(string $uuid, string $accountId = 'configured-account'): string
    {
        return json_encode([
            'event' => 'recording.transcript_completed',
            'event_ts' => 1710000000000,
            'payload' => [
                'account_id' => $accountId,
                'object' => [
                    'id' => 111,
                    'uuid' => $uuid,
                    'topic' => 'Weekly sync',
                    'start_time' => '2026-09-10T08:00:00Z',
                    'timezone' => 'UTC',
                    'duration' => 30,
                    'host_id' => 'host-1',
                    'host_email' => 'host@example.test',
                    'recording_files' => [
                        [
                            'id' => 'file-'.$uuid,
                            'file_type' => 'TRANSCRIPT',
                            'file_extension' => 'VTT',
                            'recording_type' => 'audio_transcript',
                            'status' => 'completed',
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
