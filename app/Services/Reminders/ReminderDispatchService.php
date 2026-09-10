<?php

namespace App\Services\Reminders;

use App\Enums\AutomationRunOutcome;
use App\Enums\AutomationType;
use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Services\Automation\AutomationExecutor;
use App\Services\Automation\AutomationResult;
use App\Services\Automation\AutomationRunKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ReminderDispatchService
{
    public function __construct(
        private readonly ReminderDeliveryService $delivery,
        private readonly AutomationExecutor $executor = new AutomationExecutor,
    ) {}

    public function dispatchDue(int $limit = 25): int
    {
        $claimed = $this->claimDue($limit);

        foreach ($claimed as $reminder) {
            $user = $reminder->user ?? $reminder->loadMissing('user')->user;
            if ($user === null) {
                continue;
            }

            $runAt = $reminder->run_at instanceof CarbonImmutable
                ? $reminder->run_at
                : CarbonImmutable::now('UTC');
            $runKey = AutomationRunKey::reminder((int) $reminder->id, $runAt);
            $this->executor->run(
                $user,
                AutomationType::Reminder,
                (int) $reminder->id,
                $runKey,
                function () use ($reminder): AutomationResult {
                    $this->delivery->deliver($reminder);
                    $fresh = $reminder->fresh() ?? $reminder;
                    $delivered = $fresh->status === ReminderStatus::Delivered;
                    $status = $delivered
                        ? AutomationRunOutcome::Success
                        : AutomationRunOutcome::Retryable;

                    return AutomationResult::of(
                        $status,
                        $fresh->status->value,
                        '',
                        0,
                        [],
                        $delivered ? 'success' : 'retryable',
                    );
                },
                $runAt,
                $runKey,
                rethrowRetryable: false,
            );
        }

        return count($claimed);
    }

    /**
     * @return list<Reminder>
     */
    public function claimDue(int $limit = 25): array
    {
        return DB::transaction(function () use ($limit): array {
            $query = Reminder::query()
                ->where('status', ReminderStatus::Scheduled)
                ->where('run_at', '<=', now())
                ->where(function ($builder): void {
                    $builder
                        ->whereNull('metadata->next_retry_at')
                        ->orWhere('metadata->next_retry_at', '<=', now()->utc()->toDateTimeString());
                })
                ->orderBy('run_at')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate();

            if (method_exists($query, 'skipLocked')) {
                $query->skipLocked();
            }

            $reminders = $query->get();

            foreach ($reminders as $reminder) {
                $reminder->forceFill([
                    'status' => ReminderStatus::Processing,
                ])->save();
            }

            return $reminders->all();
        });
    }
}
