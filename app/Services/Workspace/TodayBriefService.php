<?php

namespace App\Services\Workspace;

use App\Models\User;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Reminders\ReminderService;
use App\Services\Reports\ScheduledReportService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;

final class TodayBriefService
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly ReminderService $reminders,
        private readonly JarvisNotificationService $notifications,
        private readonly ScheduledReportService $reports,
    ) {}

    /**
     * Attention-reduced Today payload from existing domains (no People/Commitments/Meetings).
     *
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $timezone = (string) ($user->timezone ?: 'UTC');
        $now = CarbonImmutable::now($timezone);
        $taskPanel = $this->safeTaskPanel($user);
        $reminderPanel = $this->safeReminderPanel($user);
        $inbox = $this->safeInbox($user);
        $reportPanel = $this->safeReportPanel($user);

        $dueTasks = array_slice(array_merge(
            $taskPanel['overdue'] ?? [],
            $taskPanel['today'] ?? [],
        ), 0, 8);

        $reminders = array_slice(array_merge(
            $reminderPanel['due'] ?? [],
            $reminderPanel['today'] ?? [],
            $reminderPanel['upcoming'] ?? [],
        ), 0, 8);
        $notifications = array_slice($inbox['items'] ?? [], 0, 6);
        $reports = array_slice($reportPanel['items'] ?? [], 0, 4);

        return [
            'date_label' => $now->locale('ru')->isoFormat('dddd, D MMMM'),
            'summary' => $this->summary(count($dueTasks), count($reminders), (int) ($inbox['unread_count'] ?? 0)),
            'tasks' => $dueTasks,
            'reminders' => $reminders,
            'notifications' => $notifications,
            'reports' => $reports,
            'calendar' => [],
            'calendar_hint' => 'Календарь на сегодня можно спросить в чате. Живой Google Calendar не подгружается на этот экран.',
            'ask_href' => '/lavr',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function safeTaskPanel(User $user): array
    {
        try {
            return $this->tasks->panelFor($user);
        } catch (\Throwable) {
            return ['today' => [], 'overdue' => []];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function safeReminderPanel(User $user): array
    {
        try {
            return $this->reminders->panelFor($user);
        } catch (\Throwable) {
            return ['upcoming' => []];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function safeInbox(User $user): array
    {
        try {
            return $this->notifications->panelFor($user, true);
        } catch (\Throwable) {
            return ['unread_count' => 0, 'items' => []];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function safeReportPanel(User $user): array
    {
        try {
            return $this->reports->panelFor($user);
        } catch (\Throwable) {
            return ['items' => []];
        }
    }

    private function summary(int $dueTasks, int $reminders, int $unread): string
    {
        $parts = [];

        if ($dueTasks > 0) {
            $parts[] = $dueTasks.' задач';
        }

        if ($reminders > 0) {
            $parts[] = $reminders.' напоминаний';
        }

        if ($unread > 0) {
            $parts[] = $unread.' уведомлений';
        }

        if ($parts === []) {
            return 'На сегодня нет срочных пунктов. Можно спросить LAVR, что главное.';
        }

        return 'В фокусе: '.implode(', ', $parts).'.';
    }
}
