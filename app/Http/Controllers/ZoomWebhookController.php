<?php

namespace App\Http\Controllers;

use App\Enums\ZoomWebhookEventStatus;
use App\Jobs\ProcessZoomTranscriptJob;
use App\Services\Zoom\Exceptions\ZoomException;
use App\Services\Zoom\ZoomCredentialService;
use App\Services\Zoom\ZoomWebhookService;
use App\Services\Zoom\ZoomWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ZoomWebhookController extends Controller
{
    public function __construct(
        private readonly ZoomWebhookVerifier $verifier,
        private readonly ZoomWebhookService $webhooks,
        private readonly ZoomCredentialService $credentials,
    ) {}

    public function __invoke(Request $request): JsonResponse|Response
    {
        try {
            $raw = $this->verifier->assertBodySize($request);
            $account = $this->credentials->configuredAccount();
            $secret = $this->credentials->webhookSecret($account);

            $this->verifier->verify($request, $raw, $secret);

            $payload = json_decode($raw, true);

            if (! is_array($payload)) {
                return response()->json(['error' => 'Invalid payload.'], Response::HTTP_BAD_REQUEST);
            }

            $eventName = trim((string) ($payload['event'] ?? ''));

            if ($eventName === 'endpoint.url_validation') {
                $plainToken = trim((string) ($payload['payload']['plainToken'] ?? ''));

                if ($plainToken === '') {
                    return response()->json(['error' => 'Invalid challenge.'], Response::HTTP_BAD_REQUEST);
                }

                return response()->json([
                    'plainToken' => $plainToken,
                    'encryptedToken' => $this->verifier->encryptedToken($plainToken, $secret),
                ]);
            }

            $this->verifier->assertAccountId(
                $payload['payload']['account_id'] ?? null,
                $account !== null ? $this->credentials->credentials($account)['account_id'] : '',
            );

            $event = $this->webhooks->persist($payload);
            $duplicate = ! $event->wasRecentlyCreated
                && $event->status !== ZoomWebhookEventStatus::Received;

            if ($account !== null) {
                $this->credentials->mergeMetadata($account, [
                    'last_event_at' => now()->toIso8601String(),
                    'last_event_name' => $eventName,
                ]);
            }

            if ($duplicate) {
                Log::info('zoom webhook duplicate ignored', [
                    'zoom_event_id' => $event->id,
                    'event_type' => $eventName,
                ]);

                return response()->noContent();
            }

            if ($eventName === 'recording.completed') {
                $event->forceFill([
                    'status' => ZoomWebhookEventStatus::Ignored,
                    'processed_at' => now(),
                ])->save();

                return response()->noContent();
            }

            if (! $this->webhooks->isTranscriptCompleted($eventName)) {
                $event->forceFill([
                    'status' => ZoomWebhookEventStatus::Ignored,
                    'processed_at' => now(),
                ])->save();

                return response()->noContent();
            }

            if ($account === null || ! $this->credentials->isEnabled($account)) {
                $event->forceFill([
                    'status' => ZoomWebhookEventStatus::Ignored,
                    'processed_at' => now(),
                    'error_message' => 'disabled',
                ])->save();

                return response()->noContent();
            }

            ProcessZoomTranscriptJob::dispatch($event->id);

            $event->forceFill([
                'status' => ZoomWebhookEventStatus::Dispatched,
            ])->save();

            Log::info('zoom webhook accepted', [
                'zoom_event_id' => $event->id,
                'event_type' => $eventName,
                'meeting_uuid_hash' => $event->meeting_uuid
                    ? substr(hash('sha256', $event->meeting_uuid), 0, 12)
                    : null,
            ]);

            return response()->noContent();
        } catch (ZoomException $exception) {
            $status = match ($exception->error) {
                'payload_too_large' => Response::HTTP_PAYLOAD_TOO_LARGE,
                'expired_timestamp', 'invalid_signature', 'wrong_account' => Response::HTTP_UNAUTHORIZED,
                'blocked_auth' => Response::HTTP_UNAUTHORIZED,
                default => Response::HTTP_BAD_REQUEST,
            };

            Log::warning('zoom webhook rejected', [
                'error' => $exception->error,
            ]);

            return response()->json(['error' => 'Forbidden.'], $status);
        } catch (Throwable $exception) {
            Log::error('zoom webhook failed', [
                'error_class' => $exception::class,
            ]);

            return response()->json(['error' => 'Webhook unavailable.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }
}
