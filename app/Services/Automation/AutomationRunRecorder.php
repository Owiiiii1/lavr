<?php

namespace App\Services\Automation;

use App\Enums\AutomationRunOutcome;
use App\Enums\AutomationType;
use App\Models\AutomationRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AutomationRunRecorder
{
    public function start(
        User $user,
        AutomationType $type,
        int $automationId,
        string $runKey,
        ?CarbonImmutable $scheduledFor = null,
        ?string $deliveryKey = null,
    ): AutomationRun {
        $existing = AutomationRun::query()
            ->where('user_id', $user->id)
            ->where('run_key', $runKey)
            ->first();

        if ($existing !== null) {
            if (! $this->alreadyFinished($existing)) {
                $existing->forceFill([
                    'attempt' => (int) $existing->attempt + 1,
                    'status' => AutomationRunOutcome::Processing,
                    'started_at' => CarbonImmutable::now('UTC'),
                    'finished_at' => null,
                    'safe_error' => null,
                ])->save();

                return $existing->fresh() ?? $existing;
            }

            return $existing;
        }

        return AutomationRun::query()->create([
            'user_id' => $user->id,
            'automation_type' => $type,
            'automation_id' => $automationId,
            'run_key' => $runKey,
            'scheduled_for' => $scheduledFor,
            'started_at' => CarbonImmutable::now('UTC'),
            'status' => AutomationRunOutcome::Processing,
            'attempt' => 1,
            'delivery_key' => $deliveryKey ?? $runKey,
        ]);
    }

    public function finish(AutomationRun $run, AutomationResult $result): AutomationRun
    {
        $run->forceFill([
            'status' => $result->status,
            'outcome_code' => $result->reasonCode,
            'delivery_status' => $result->deliveryStatus,
            'metrics' => $this->boundedMetrics($result->metrics),
            'safe_error' => $result->errorClass,
            'finished_at' => CarbonImmutable::now('UTC'),
        ])->save();

        Log::info('automation run finished', [
            'automation_type' => $run->automation_type instanceof AutomationType ? $run->automation_type->value : (string) $run->automation_type,
            'automation_id' => $run->automation_id,
            'run_id' => $run->id,
            'run_key' => $run->run_key,
            'attempt' => $run->attempt,
            'status' => $result->status->value,
            'outcome' => $result->reasonCode,
            'duration_ms' => $result->durationMs,
        ]);

        return $run->fresh() ?? $run;
    }

    public function fail(AutomationRun $run, Throwable $exception, bool $retryable): AutomationRun
    {
        return $this->finish($run, AutomationResult::of(
            $retryable ? AutomationRunOutcome::Retryable : AutomationRunOutcome::Failed,
            $retryable ? 'retryable' : 'failed',
            '',
            0,
            [],
            'failed',
            $exception::class,
        ));
    }

    public function alreadyFinished(AutomationRun $run): bool
    {
        $status = $run->status instanceof AutomationRunOutcome
            ? $run->status
            : AutomationRunOutcome::tryFrom((string) $run->status);

        return $status !== null && $status->isTerminal();
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function boundedMetrics(array $metrics): array
    {
        unset($metrics['body'], $metrics['text'], $metrics['excerpt'], $metrics['prompt'], $metrics['email']);

        return [
            'sources_attempted' => (int) ($metrics['sources_attempted'] ?? 0),
            'sources_succeeded' => (int) ($metrics['sources_succeeded'] ?? 0),
            'sources_failed' => (int) ($metrics['sources_failed'] ?? 0),
            'items_collected' => (int) ($metrics['items_collected'] ?? 0),
            'items_rendered' => (int) ($metrics['items_rendered'] ?? 0),
            'delivery_latency_ms' => (int) ($metrics['delivery_latency_ms'] ?? 0),
            'total_duration_ms' => (int) ($metrics['total_duration_ms'] ?? 0),
            'prompt_version' => isset($metrics['prompt_version']) ? (string) $metrics['prompt_version'] : null,
            'ai_used' => (bool) ($metrics['ai_used'] ?? false),
        ];
    }
}
