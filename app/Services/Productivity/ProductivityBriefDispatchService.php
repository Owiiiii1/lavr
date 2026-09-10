<?php

namespace App\Services\Productivity;

use App\Enums\JarvisNotificationType;
use App\Enums\ProductivityBriefMode;
use App\Enums\ScheduledReportType;
use App\Enums\TaskStatus;
use App\Models\JarvisNotification;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductivitySetting;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Notifications\NotificationUrlPolicy;
use App\Services\Reminders\ReminderLifecycle;
use App\Services\Reports\ScheduledReportService;
use App\Services\Tasks\TaskLifecycle;
use App\Services\Users\UserCapability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

final class ProductivityBriefDispatchService
{
    public function __construct(
        private readonly ProductivitySettingsService $settings,
        private readonly ProductivityBriefService $briefs,
        private readonly ScheduledReportService $reports,
        private readonly JarvisNotificationService $inbox,
        private readonly NotificationUrlPolicy $urls,
    ) {}

    public function dispatchDue(int $limit = 40): int
    {
        if (! Schema::hasTable('user_productivity_settings')) {
            return 0;
        }

        $now = CarbonImmutable::now('UTC');
        $created = 0;
        $rows = UserProductivitySetting::query()
            ->with('user')
            ->where(function ($query): void {
                $query->where('daily_brief_enabled', true)
                    ->orWhere('evening_review_enabled', true)
                    ->orWhere('weekly_review_enabled', true);
            })
            ->limit(max(1, $limit))
            ->get();

        foreach ($rows as $settings) {
            $user = $settings->user;

            if (! $user instanceof User || ! $user->isActive()) {
                continue;
            }

            foreach (ProductivityBriefMode::cases() as $mode) {
                if ($mode === ProductivityBriefMode::Daily && (bool) $settings->morning_brief_enabled) {
                    continue;
                }

                if (! $this->settings->isDue($settings, $mode->value, $user, $now)) {
                    continue;
                }

                if ($this->coveredByScheduledReport($user, $mode)) {
                    continue;
                }

                $payload = $this->briefs->compose(
                    $user,
                    $mode,
                    $now,
                    $this->tasksFor($user),
                    $this->remindersFor($user),
                    [],
                    $this->projectsFor($user),
                    $this->recentNotifications($user),
                );

                $dedupe = $this->dedupeKey($mode, $user, $now);
                $row = $this->inbox->record(
                    $user,
                    JarvisNotificationType::BriefReady,
                    match ($mode) {
                        ProductivityBriefMode::Daily => 'Ежедневная сводка',
                        ProductivityBriefMode::Evening => 'Вечерний обзор',
                        ProductivityBriefMode::Weekly => 'Недельный обзор',
                    },
                    $payload['text'],
                    $dedupe,
                    'brief',
                    null,
                    $this->urls->workspacePath($user, 'notifications=1'),
                    [
                        'trigger' => $mode->value,
                        'ai_phrased' => $payload['ai_used'],
                        'mode' => $mode->value,
                    ],
                    $payload['ai_used'],
                );

                $this->touch($settings, $mode, $now);

                if ($row !== null) {
                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * @return list<Task>
     */
    private function tasksFor(User $user): array
    {
        return Task::query()
            ->where('user_id', $user->id)
            ->where(function ($query): void {
                $query->whereIn('status', TaskLifecycle::openStatuses())
                    ->orWhere('status', TaskStatus::Completed);
            })
            ->orderByDesc('updated_at')
            ->limit(80)
            ->get()
            ->all();
    }

    /**
     * @return list<Reminder>
     */
    private function remindersFor(User $user): array
    {
        return Reminder::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ReminderLifecycle::openStatuses())
            ->orderBy('run_at')
            ->limit(20)
            ->get()
            ->all();
    }

    /**
     * @return list<Project>
     */
    private function projectsFor(User $user): array
    {
        if (! $user->canUseCapability(UserCapability::PROJECTS)) {
            return [];
        }

        return Project::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->all();
    }

    /**
     * @return list<JarvisNotification>
     */
    private function recentNotifications(User $user): array
    {
        return JarvisNotification::query()
            ->where('user_id', $user->id)
            ->where('occurred_at', '>=', CarbonImmutable::now('UTC')->subDay())
            ->orderByDesc('occurred_at')
            ->limit(5)
            ->get()
            ->all();
    }

    private function dedupeKey(ProductivityBriefMode $mode, User $user, CarbonImmutable $now): string
    {
        $timezone = (string) ($user->timezone ?: 'UTC');
        $local = $now->utc()->setTimezone($timezone);

        if ($mode === ProductivityBriefMode::Weekly) {
            return 'brief_ready:weekly:'.$local->isoWeekYear().'-'.$local->isoWeek();
        }

        return 'brief_ready:'.$mode->value.':'.$local->toDateString();
    }

    private function touch(UserProductivitySetting $settings, ProductivityBriefMode $mode, CarbonImmutable $now): void
    {
        match ($mode) {
            ProductivityBriefMode::Daily => $settings->last_daily_brief_at = $now->utc(),
            ProductivityBriefMode::Evening => $settings->last_evening_review_at = $now->utc(),
            ProductivityBriefMode::Weekly => $settings->last_weekly_review_at = $now->utc(),
        };

        $settings->save();
    }

    private function coveredByScheduledReport(User $user, ProductivityBriefMode $mode): bool
    {
        $types = match ($mode) {
            ProductivityBriefMode::Daily => [ScheduledReportType::DailyPlan],
            ProductivityBriefMode::Evening => [ScheduledReportType::TomorrowPlan],
            ProductivityBriefMode::Weekly => [],
        };

        return $this->reports->hasActiveOfTypes($user, $types);
    }
}
