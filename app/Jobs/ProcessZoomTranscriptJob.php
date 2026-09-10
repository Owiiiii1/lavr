<?php

namespace App\Jobs;

use App\Enums\AsyncFailureCategory;
use App\Enums\ZoomImportStatus;
use App\Enums\ZoomWebhookEventStatus;
use App\Jobs\Concerns\HandlesClassifiedAsyncFailure;
use App\Models\Meeting;
use App\Models\ZoomWebhookEvent;
use App\Services\Reliability\Exceptions\ClassifiedAsyncException;
use App\Services\Zoom\Exceptions\ZoomException;
use App\Services\Zoom\ZoomConfig;
use App\Services\Zoom\ZoomMeetingIngestor;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessZoomTranscriptJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use HandlesClassifiedAsyncFailure;
    use Queueable;

    public int $tries = 6;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $zoomWebhookEventId,
    ) {
        $this->onQueue(ZoomConfig::queue());
        $this->tries = ZoomConfig::jobTries();
        $this->timeout = ZoomConfig::jobTimeout();
    }

    public function uniqueId(): string
    {
        return 'zoom-transcript-'.$this->zoomWebhookEventId;
    }

    public function handle(ZoomMeetingIngestor $ingestor): void
    {
        $event = ZoomWebhookEvent::query()->find($this->zoomWebhookEventId);

        if ($event === null) {
            return;
        }

        try {
            $ingestor->ingest($event);
        } catch (ZoomException $exception) {
            $this->recordFailure($event, $exception);

            if ($exception->retryAfterSeconds !== null) {
                $this->release($exception->retryAfterSeconds);

                return;
            }

            if ($exception->retryable) {
                throw new ClassifiedAsyncException(
                    AsyncFailureCategory::Network,
                    $exception->error,
                    true,
                    $exception,
                );
            }

            return;
        } catch (Throwable $exception) {
            $failure = $this->classifyFailure($exception);
            $this->failureWriter()->logFailure('zoom transcript ingest failed', $failure, [
                'zoom_event_id' => $this->zoomWebhookEventId,
                'job_attempt' => $this->attempts(),
            ]);

            if (! $failure->retryable) {
                $this->markEventFailed($event, $failure->code);

                return;
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $event = ZoomWebhookEvent::query()->find($this->zoomWebhookEventId);

        if ($event === null) {
            return;
        }

        $code = $exception instanceof ZoomException ? $exception->error : 'ingest_failed';
        $this->markEventFailed($event, $code);
        $this->failureWriter()->logFailure('zoom transcript ingest exhausted retries', $this->classifyFailure($exception), [
            'zoom_event_id' => $this->zoomWebhookEventId,
        ]);
    }

    private function recordFailure(ZoomWebhookEvent $event, ZoomException $exception): void
    {
        $status = match ($exception->error) {
            'blocked_auth' => ZoomImportStatus::BlockedAuth,
            'transcript_unavailable' => ZoomImportStatus::TranscriptUnavailable,
            default => ZoomImportStatus::Failed,
        };

        $event->forceFill([
            'status' => $exception->retryable ? ZoomWebhookEventStatus::Received : ZoomWebhookEventStatus::Failed,
            'error_class' => $exception->error,
            'error_message' => $exception->error,
            'attempts' => $event->attempts + 1,
        ])->save();

        if ($event->meeting_id) {
            $meeting = Meeting::query()->find($event->meeting_id);

            if ($meeting !== null) {
                app(ZoomMeetingIngestor::class)->markFailure($meeting, $status, $exception->error);
            }
        }

        Log::warning('zoom transcript ingest error', [
            'zoom_event_id' => $event->id,
            'meeting_id' => $event->meeting_id,
            'error' => $exception->error,
            'job_attempt' => $this->attempts(),
            'retryable' => $exception->retryable,
        ]);
    }

    private function markEventFailed(ZoomWebhookEvent $event, string $code): void
    {
        $event->forceFill([
            'status' => ZoomWebhookEventStatus::Failed,
            'error_class' => $code,
            'error_message' => $code,
            'processed_at' => now(),
        ])->save();

        if ($event->meeting_id) {
            $meeting = Meeting::query()->find($event->meeting_id);

            if ($meeting !== null) {
                $status = $code === 'blocked_auth'
                    ? ZoomImportStatus::BlockedAuth
                    : ($code === 'transcript_unavailable' ? ZoomImportStatus::TranscriptUnavailable : ZoomImportStatus::Failed);
                app(ZoomMeetingIngestor::class)->markFailure($meeting, $status, $code);
            }
        }
    }
}
