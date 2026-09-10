<?php

namespace App\Jobs;

use App\Enums\AutomationRunOutcome;
use App\Enums\AutomationType;
use App\Enums\WatcherHealth;
use App\Enums\WatcherStatus;
use App\Jobs\Concerns\HandlesClassifiedAsyncFailure;
use App\Models\User;
use App\Models\Watcher;
use App\Services\Automation\AutomationEvent;
use App\Services\Automation\AutomationEventBus;
use App\Services\Automation\AutomationExecutor;
use App\Services\Automation\AutomationResult;
use App\Services\Automation\AutomationRunKey;
use App\Services\Watchers\WatcherEvaluationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class EvaluateWatcherJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use HandlesClassifiedAsyncFailure;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $watcherId,
    ) {
        $this->onQueue((string) config('watchers.queue', 'default'));
        $this->tries = max(1, (int) config('reliability.job_tries', 3));
    }

    public function uniqueId(): string
    {
        return 'watcher-'.$this->watcherId;
    }

    public function handle(WatcherEvaluationService $evaluation, AutomationExecutor $executor, AutomationEventBus $events): void
    {
        $watcher = Watcher::query()->find($this->watcherId);
        if ($watcher === null || $watcher->status !== WatcherStatus::Active) {
            return;
        }

        $user = $watcher->user ?? User::query()->find($watcher->user_id);
        if ($user === null) {
            return;
        }

        $slot = CarbonImmutable::now('UTC')->startOfMinute();
        $runKey = AutomationRunKey::watcherPoll((int) $watcher->id, $slot);

        $executor->run(
            $user,
            AutomationType::Watcher,
            (int) $watcher->id,
            $runKey,
            function () use ($evaluation, $watcher, $events, $user): AutomationResult {
                $occurrence = $evaluation->evaluate($watcher);
                $fresh = $watcher->fresh() ?? $watcher;

                if ($occurrence !== null) {
                    $events->emit(new AutomationEvent('watcher.matched', (int) $user->id, (int) $watcher->id));

                    return AutomationResult::of(AutomationRunOutcome::Success, 'matched', '', 0, [
                        'items_collected' => 1,
                        'items_rendered' => 1,
                    ], 'success');
                }

                if ($fresh->health === WatcherHealth::Blocked) {
                    return AutomationResult::of(AutomationRunOutcome::Failed, 'blocked_auth', '', 0, [], 'skipped', 'blocked_auth');
                }

                return AutomationResult::of(AutomationRunOutcome::NoChange, 'no_match', '', 0, [], 'skipped');
            },
            $slot,
            $runKey,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $watcher = Watcher::query()->find($this->watcherId);
        if ($watcher === null) {
            return;
        }

        $failure = $this->classifyFailure($exception);
        $this->failureWriter()->logFailure('watcher evaluation failed', $failure, [
            'watcher_id' => $this->watcherId,
            'user_id' => $watcher->user_id,
        ]);
    }
}
