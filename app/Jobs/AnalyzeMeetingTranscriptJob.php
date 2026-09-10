<?php

namespace App\Jobs;

use App\Enums\AsyncFailureCategory;
use App\Enums\JarvisNotificationType;
use App\Enums\MeetingAnalysisStatus;
use App\Enums\MeetingSourceType;
use App\Jobs\Concerns\HandlesClassifiedAsyncFailure;
use App\Models\Meeting;
use App\Models\MeetingAnalysis;
use App\Models\User;
use App\Services\Meetings\Exceptions\MeetingIntelligenceException;
use App\Services\Meetings\MeetingConfig;
use App\Services\Meetings\MeetingIntelligencePipeline;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Reliability\Exceptions\ClassifiedAsyncException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeMeetingTranscriptJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use HandlesClassifiedAsyncFailure;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $meetingId,
        public readonly int $userId,
    ) {
        $this->onQueue(MeetingConfig::queue());
        $this->tries = max(1, (int) config('reliability.job_tries', 3));
        $this->timeout = MeetingConfig::jobTimeout();
    }

    public function uniqueId(): string
    {
        return 'meeting-analysis-'.$this->meetingId;
    }

    public function handle(MeetingIntelligencePipeline $pipeline): void
    {
        $meeting = Meeting::query()->whereKey($this->meetingId)->where('user_id', $this->userId)->first();
        $user = User::query()->find($this->userId);

        if ($meeting === null || $user === null) {
            return;
        }

        try {
            $pipeline->analyze($user, $meeting);

            if ($meeting->source_type === MeetingSourceType::Zoom) {
                $user->loadMissing('assistantProfile');
                $this->notifyZoomAnalysisCompleted($user, $meeting->fresh() ?? $meeting);
            }
        } catch (MeetingIntelligenceException $exception) {
            $this->failureWriter()->logFailure('meeting intelligence failed', $this->classifyFailure($exception), [
                'meeting_id' => $this->meetingId,
                'user_id' => $this->userId,
                'error' => $exception->error,
            ]);

            throw new ClassifiedAsyncException(
                AsyncFailureCategory::MalformedProviderResponse,
                $exception->error,
                false,
                $exception,
            );
        } catch (Throwable $exception) {
            $failure = $this->classifyFailure($exception);
            $this->failureWriter()->logFailure('meeting intelligence job failed', $failure, [
                'meeting_id' => $this->meetingId,
                'user_id' => $this->userId,
            ]);

            if (! $failure->retryable) {
                $this->markFailed($failure->code);

                return;
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $failure = $this->classifyFailure($exception);
        $this->markFailed($failure->code);
        $this->failureWriter()->logFailure('meeting intelligence exhausted retries', $failure, [
            'meeting_id' => $this->meetingId,
            'user_id' => $this->userId,
        ]);
    }

    private function markFailed(string $code): void
    {
        $meeting = Meeting::query()->whereKey($this->meetingId)->where('user_id', $this->userId)->first();

        if ($meeting === null) {
            return;
        }

        $analysis = $meeting->currentAnalysis
            ?? $meeting->analyses()->latest('id')->first();

        if ($analysis instanceof MeetingAnalysis && $analysis->status !== MeetingAnalysisStatus::Completed) {
            $analysis->forceFill([
                'status' => MeetingAnalysisStatus::Failed,
                'error_class' => 'job',
                'error_message' => $code,
                'processed_at' => now(),
            ])->save();
        }

        if ($meeting->analysis_status !== MeetingAnalysisStatus::Completed) {
            $meeting->forceFill([
                'analysis_status' => MeetingAnalysisStatus::Failed,
            ])->save();
        }

        Log::warning('meeting intelligence marked failed', [
            'meeting_id' => $meeting->id,
            'analysis_id' => $analysis?->id,
            'error' => $code,
        ]);
    }

    private function notifyZoomAnalysisCompleted(User $user, Meeting $meeting): void
    {
        $locale = $user->assistantProfile?->interface_locale ?? 'uk';
        $title = match ($locale) {
            'en' => 'Meeting analyzed',
            'ru' => 'Встреча проанализирована',
            default => 'Зустріч проаналізовано',
        };

        app(JarvisNotificationService::class)->record(
            $user,
            JarvisNotificationType::MeetingAnalyzed,
            $title,
            $meeting->title,
            'meeting_analyzed:'.$meeting->id,
            'meeting',
            $meeting->id,
            '/lavr/meetings/'.$meeting->id,
        );
    }
}
