<?php

namespace App\Services\OperationalControl;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OperationalControlScanService
{
    public function __construct(
        private readonly OperationalRuleRegistry $rules,
        private readonly OperationalEventRecorder $events,
        private readonly OperationalAssessmentService $assessment,
        private readonly ProactiveProposalService $proposals,
        private readonly ProactiveProposalReconciler $reconciler,
        private readonly OperationalNotificationGate $notifications,
    ) {}

    public function scanAll(int $limit = 40): int
    {
        $users = User::query()
            ->where('role', UserRole::Owner)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $count = 0;
        foreach ($users as $user) {
            $count += $this->scanUser($user);
        }

        return $count;
    }

    public function scanUser(User $user): int
    {
        if (! $user->isActive() || ! $user->isOwner()) {
            return 0;
        }

        $lock = Cache::lock('operational-scan:'.$user->id, 120);
        if (! $lock->get()) {
            return 0;
        }

        try {
            $this->reconciler->reconcileUser($user);
            $created = 0;
            foreach ($this->rules->evaluate($user) as $match) {
                $event = $this->events->record($user, $match);
                $assessment = $this->assessment->assess($user, $event, $match);
                $event = $this->assessment->apply($event, $assessment);
                $proposal = $this->proposals->propose($user, $event, $match, $assessment);
                if ($proposal !== null) {
                    $created++;
                    $this->notifications->maybeNotify($user, $proposal, $assessment->notify);
                }
            }

            return $created;
        } catch (Throwable $exception) {
            Log::info('operational control scan failed', [
                'user_id' => $user->id,
                'error_class' => $exception::class,
            ]);
            if (app()->runningUnitTests()) {
                throw $exception;
            }

            return 0;
        } finally {
            $lock->release();
        }
    }
}
