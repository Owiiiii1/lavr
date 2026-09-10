<?php

namespace App\Services\Watchers;

use App\Enums\JarvisNotificationType;
use App\Enums\WatcherReactionStatus;
use App\Enums\WatcherReactionType;
use App\Models\User;
use App\Models\Watcher;
use App\Models\WatcherOccurrence;
use App\Services\Automation\ExternalActionPolicy;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Notifications\NotificationUrlPolicy;
use App\Services\Reminders\ReminderService;
use App\Services\Tasks\TaskService;
use App\Services\Watchers\DTO\WatcherObservation;
use Carbon\CarbonImmutable;
use Throwable;

final class WatcherReactionExecutor
{
    public function __construct(
        private readonly JarvisNotificationService $notifications,
        private readonly NotificationUrlPolicy $urls,
        private readonly TaskService $tasks,
        private readonly ReminderService $reminders,
        private readonly WatcherAnalysisService $analysis,
        private readonly ExternalActionPolicy $policy = new ExternalActionPolicy,
    ) {}

    public function execute(User $user, Watcher $watcher, WatcherOccurrence $occurrence, WatcherObservation $observation): void
    {
        $occurrence->refresh();
        if (in_array($occurrence->reaction_status, [WatcherReactionStatus::Executed, WatcherReactionStatus::Proposed], true)) {
            return;
        }

        $config = is_array($watcher->reaction_config) ? $watcher->reaction_config : [];

        try {
            match ($watcher->reaction_type) {
                WatcherReactionType::CreateTask => $this->createTask($user, $watcher, $occurrence, $observation, $config),
                WatcherReactionType::CreateReminder => $this->createReminder($user, $watcher, $occurrence, $observation, $config),
                WatcherReactionType::RunInternalAnalysis => $this->analyze($user, $watcher, $occurrence, $observation),
                WatcherReactionType::ProposeAction => $this->propose($user, $watcher, $occurrence, $observation, $config),
                default => $this->notify($user, $watcher, $occurrence, $observation, $observation->title),
            };
        } catch (Throwable $exception) {
            $occurrence->forceFill([
                'reaction_status' => WatcherReactionStatus::Failed,
                'error_category' => 'reaction_failed',
            ])->save();
            throw $exception;
        }
    }

    public function notifyBlocked(User $user, Watcher $watcher): void
    {
        $this->notifications->record(
            $user,
            JarvisNotificationType::WatcherTriggered,
            'Watcher needs reconnect',
            '«'.$watcher->name.'» is blocked until the integration is reconnected.',
            'watcher-blocked:'.$watcher->id,
            'watcher',
            (int) $watcher->id,
            $this->urls->workspacePath($user, 'watchers=1'),
            ['watcher_id' => $watcher->id, 'trigger' => 'blocked'],
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createTask(User $user, Watcher $watcher, WatcherOccurrence $occurrence, WatcherObservation $observation, array $config): void
    {
        $title = trim((string) ($config['title'] ?? $observation->title));
        $task = $this->tasks->create($user, $title !== '' ? $title : $watcher->name, WatcherSupport::summary((string) ($config['description'] ?? $observation->title)));
        $this->notify($user, $watcher, $occurrence, $observation, 'Created task: '.$task->title);
        $occurrence->forceFill(['reaction_status' => WatcherReactionStatus::Executed])->save();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createReminder(User $user, Watcher $watcher, WatcherOccurrence $occurrence, WatcherObservation $observation, array $config): void
    {
        $minutes = max(1, (int) ($config['in_minutes'] ?? 30));
        $timezone = (string) ($user->timezone ?: 'UTC');
        $runAt = CarbonImmutable::now($timezone)->addMinutes($minutes);
        $text = trim((string) ($config['text'] ?? $observation->title));
        $this->reminders->create($user, $text !== '' ? $text : $watcher->name, $runAt, $timezone);
        $this->notify($user, $watcher, $occurrence, $observation, 'Created reminder: '.$text);
        $occurrence->forceFill(['reaction_status' => WatcherReactionStatus::Executed])->save();
    }

    private function analyze(User $user, Watcher $watcher, WatcherOccurrence $occurrence, WatcherObservation $observation): void
    {
        $body = $this->analysis->summarize($user, $watcher, $observation);
        $this->notify($user, $watcher, $occurrence, $observation, $body, aiPhrased: true);
        $occurrence->forceFill(['reaction_status' => WatcherReactionStatus::Executed])->save();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function propose(User $user, Watcher $watcher, WatcherOccurrence $occurrence, WatcherObservation $observation, array $config): void
    {
        $tool = trim((string) ($config['tool'] ?? $config['proposed_tool'] ?? 'send_gmail_message'));
        $this->policy->levelFor($user, $tool);
        $this->notify(
            $user,
            $watcher,
            $occurrence,
            $observation,
            'Proposed action «'.$tool.'» is waiting for your confirmation. LAVR did not send anything.',
            [
                'pending_action' => [
                    'tool' => $tool,
                    'status' => 'needs_confirmation',
                ],
            ],
        );
        $occurrence->forceFill(['reaction_status' => WatcherReactionStatus::Proposed])->save();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function notify(
        User $user,
        Watcher $watcher,
        WatcherOccurrence $occurrence,
        WatcherObservation $observation,
        string $body,
        array $extra = [],
        bool $aiPhrased = false,
    ): void {
        $max = WatcherSchedule::isDigest($watcher)
            ? max(200, (int) config('watchers.defaults.digest_max_chars', 800))
            : null;
        $text = WatcherSchedule::isDigest($watcher)
            ? WatcherSupport::clip($body !== '' ? $body : $observation->title, $max)
            : WatcherSupport::summary($body !== '' ? $body : $observation->title, $max);

        $notification = $this->notifications->record(
            $user,
            JarvisNotificationType::WatcherTriggered,
            $watcher->name,
            $text,
            'watcher:'.$watcher->id.':'.$occurrence->trigger_fingerprint,
            'watcher',
            (int) $watcher->id,
            $this->urls->workspacePath($user, 'watchers=1'),
            array_merge([
                'watcher_id' => $watcher->id,
                'occurrence_id' => $occurrence->id,
                'trigger' => $observation->eventType,
            ], $extra),
            $aiPhrased,
        );

        $occurrence->forceFill([
            'notification_id' => $notification?->id,
            'reaction_status' => $occurrence->reaction_status === WatcherReactionStatus::Proposed
                ? WatcherReactionStatus::Proposed
                : WatcherReactionStatus::Executed,
            'status' => $occurrence->status,
        ])->save();
    }
}
