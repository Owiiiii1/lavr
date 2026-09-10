<?php

namespace App\Services\Watchers\Adapters;

use App\Enums\TaskStatus;
use App\Enums\WatcherConditionType;
use App\Enums\WatcherTriggerType;
use App\Models\Task;
use App\Models\User;
use App\Models\Watcher;
use App\Services\Watchers\Contracts\WatcherSourceAdapter;
use App\Services\Watchers\DTO\WatcherObservation;
use App\Services\Watchers\WatcherSupport;
use Carbon\CarbonImmutable;

final class TaskWatcherSource implements WatcherSourceAdapter
{
    public function supports(Watcher $watcher): bool
    {
        return in_array($watcher->trigger_type, [WatcherTriggerType::TaskState, WatcherTriggerType::TimeCondition], true)
            && ($watcher->task_id !== null || (($watcher->source_config['task_id'] ?? null) !== null));
    }

    public function check(User $user, Watcher $watcher): array
    {
        $taskId = (int) ($watcher->task_id ?? ($watcher->source_config['task_id'] ?? 0));
        $task = Task::query()->where('user_id', $user->id)->whereKey($taskId)->first();

        if ($task === null) {
            return [];
        }

        $now = CarbonImmutable::now('UTC');
        $open = in_array($task->status, [TaskStatus::Open, TaskStatus::InProgress], true);
        $due = $task->due_at instanceof CarbonImmutable ? $task->due_at->utc() : null;
        $condition = $watcher->condition_type;
        $hours = (int) ($watcher->condition_config['hours'] ?? ($watcher->condition_config['within_hours'] ?? 24));
        $explicitHours = $watcher->condition_config['hours'] ?? $watcher->condition_config['within_hours'] ?? null;
        $delayElapsed = $explicitHours === null || ! is_numeric($explicitHours) || (int) $explicitHours <= 0
            || $this->createdAt($watcher)->addHours(max(1, (int) $explicitHours))->lessThanOrEqualTo($now);

        $match = match ($condition) {
            WatcherConditionType::OverdueBy => $open && $due !== null && $due->addHours(max(1, $hours))->lessThanOrEqualTo($now),
            WatcherConditionType::DeadlineWithin => $open && $due !== null && $due->greaterThan($now) && $due->lessThanOrEqualTo($now->addHours(max(1, $hours))),
            WatcherConditionType::StatusEquals => $delayElapsed
                && mb_strtolower($task->status->value) === mb_strtolower((string) ($watcher->condition_config['status'] ?? $watcher->condition_config['expected'] ?? '')),
            WatcherConditionType::StatusChanged => $open || $task->status === TaskStatus::Completed || $task->status === TaskStatus::Cancelled,
            default => $open,
        };

        if (! $match) {
            return [];
        }

        $bucket = $due?->toDateString() ?? 'none';

        return [
            new WatcherObservation(
                sourceType: 'task',
                sourceId: (string) $task->id,
                eventType: 'task_state',
                fingerprint: WatcherSupport::fingerprint('task', (string) $watcher->id, (string) $task->id, $task->status->value, $bucket, $condition->value),
                occurredAt: $now,
                title: (string) $task->title,
                metadata: [
                    'status' => $task->status->value,
                    'due_at' => $due?->toIso8601String(),
                ],
                projectId: $task->project_id,
                taskId: (int) $task->id,
            ),
        ];
    }

    private function createdAt(Watcher $watcher): CarbonImmutable
    {
        if ($watcher->created_at instanceof CarbonImmutable) {
            return $watcher->created_at->utc();
        }

        if ($watcher->created_at instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($watcher->created_at)->utc();
        }

        return CarbonImmutable::now('UTC');
    }
}
