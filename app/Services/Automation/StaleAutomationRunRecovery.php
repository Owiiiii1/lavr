<?php

namespace App\Services\Automation;

use App\Enums\AutomationRunOutcome;
use App\Models\AutomationRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final class StaleAutomationRunRecovery
{
    public function recover(int $minutes, bool $execute): int
    {
        $threshold = CarbonImmutable::now('UTC')->subMinutes(max(5, $minutes));
        $rows = AutomationRun::query()
            ->where('status', AutomationRunOutcome::Processing)
            ->where('started_at', '<=', $threshold)
            ->orderBy('id')
            ->limit(100)
            ->get();

        if ($execute) {
            foreach ($rows as $run) {
                $run->forceFill([
                    'status' => AutomationRunOutcome::Retryable,
                    'outcome_code' => 'failed_stale',
                    'safe_error' => 'stale_processing',
                    'finished_at' => CarbonImmutable::now('UTC'),
                ])->save();

                Log::info('automation run stale recovered', [
                    'automation_type' => $run->automation_type instanceof \BackedEnum ? $run->automation_type->value : (string) $run->automation_type,
                    'automation_id' => $run->automation_id,
                    'run_id' => $run->id,
                    'run_key' => $run->run_key,
                    'attempt' => $run->attempt,
                    'status' => AutomationRunOutcome::Retryable->value,
                    'outcome' => 'failed_stale',
                    'duration_ms' => $run->durationMs(),
                ]);
            }
        }

        return $rows->count();
    }
}
