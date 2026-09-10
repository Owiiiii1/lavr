<?php

namespace App\Services\Automation;

use App\Enums\AutomationRunOutcome;
use App\Enums\AutomationType;
use App\Models\AutomationRun;
use App\Models\User;
use App\Services\Reliability\AsyncFailureClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class AutomationExecutor
{
    public function __construct(
        private readonly AutomationRunRecorder $recorder = new AutomationRunRecorder,
        private readonly AsyncFailureClassifier $failures = new AsyncFailureClassifier,
    ) {}

    /**
     * @param  callable(AutomationRun): AutomationResult  $callback
     */
    public function run(
        User $user,
        AutomationType $type,
        int $automationId,
        string $runKey,
        callable $callback,
        ?CarbonImmutable $scheduledFor = null,
        ?string $deliveryKey = null,
        bool $rethrowRetryable = true,
    ): AutomationResult {
        $lock = null;
        try {
            $lock = Cache::lock('automation-run:'.$runKey, 120);
            if (! $lock->block(10)) {
                $existing = AutomationRun::query()
                    ->where('user_id', $user->id)
                    ->where('run_key', $runKey)
                    ->first();

                return $existing !== null
                    ? $this->fromExisting($existing)
                    : AutomationResult::of(AutomationRunOutcome::Skipped, 'locked', '');
            }
        } catch (Throwable) {
            $lock = null;
        }

        try {
            $run = $this->recorder->start($user, $type, $automationId, $runKey, $scheduledFor, $deliveryKey);

            if ($this->recorder->alreadyFinished($run)) {
                return $this->fromExisting($run);
            }

            $started = hrtime(true);

            try {
                $result = $callback($run);
                $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
                $metrics = $result->metrics;
                $metrics['total_duration_ms'] = (int) ($metrics['total_duration_ms'] ?? $durationMs);

                $finished = new AutomationResult(
                    $result->status,
                    $result->reasonCode,
                    $result->safeSummary,
                    $durationMs,
                    $metrics,
                    $result->deliveryStatus,
                    $result->errorClass,
                );
                $this->recorder->finish($run, $finished);

                return $finished;
            } catch (Throwable $exception) {
                $classified = $this->failures->classify($exception);
                $this->recorder->fail($run, $exception, $classified->retryable);

                if ($classified->retryable && $rethrowRetryable) {
                    throw $exception;
                }

                return AutomationResult::of(
                    AutomationRunOutcome::Failed,
                    $classified->code,
                    '',
                    (int) ((hrtime(true) - $started) / 1_000_000),
                    [],
                    'failed',
                    $exception::class,
                );
            }
        } finally {
            optional($lock)?->release();
        }
    }

    public function fromExisting(AutomationRun $run): AutomationResult
    {
        $status = $run->status instanceof AutomationRunOutcome
            ? $run->status
            : (AutomationRunOutcome::tryFrom((string) $run->status) ?? AutomationRunOutcome::Success);

        return AutomationResult::of(
            $status,
            (string) ($run->outcome_code ?? 'already_recorded'),
            '',
            (int) ($run->durationMs() ?? 0),
            is_array($run->metrics) ? $run->metrics : [],
            $run->delivery_status,
            $run->safe_error,
        );
    }
}
