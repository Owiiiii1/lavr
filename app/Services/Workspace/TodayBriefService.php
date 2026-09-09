<?php

namespace App\Services\Workspace;

use App\Models\User;
use App\Services\Integrations\Google\GoogleCalendarService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Reminders\ReminderService;
use App\Services\Reports\ScheduledReportService;
use App\Services\Tasks\TaskService;
use App\Services\Users\UserCapability;
use Carbon\CarbonImmutable;

final class TodayBriefService
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly ReminderService $reminders,
        private readonly JarvisNotificationService $notifications,
        private readonly ScheduledReportService $reports,
        private readonly IntegrationAccountService $accounts,
        private readonly GoogleCalendarService $calendar,
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
        $calendar = $this->safeCalendar($user, $now);

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
            'brand' => 'LAVR',
            'date_label' => $now->locale('ru')->isoFormat('dddd, D MMMM'),
            'summary' => $this->summary(count($dueTasks), count($reminders), (int) ($inbox['unread_count'] ?? 0), count($calendar['events'] ?? [])),
            'tasks' => $dueTasks,
            'reminders' => $reminders,
            'notifications' => $notifications,
            'reports' => $reports,
            'calendar' => $calendar['events'],
            'calendar_hint' => $calendar['hint'],
            'calendar_error' => $calendar['error'],
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

    /**
     * @return array{events: list<array<string, mixed>>, hint: ?string, error: ?string}
     */
    private function safeCalendar(User $user, CarbonImmutable $now): array
    {
        if (! $user->canUseCapability(UserCapability::GOOGLE_CALENDAR)) {
            return [
                'events' => [],
                'hint' => 'Календарь на сегодня можно спросить в чате.',
                'error' => null,
            ];
        }

        try {
            $account = $this->accounts->getActiveAccount($user, 'google');

            if ($account === null) {
                return [
                    'events' => [],
                    'hint' => 'Календарь не подключен. События дня можно спросить в чате.',
                    'error' => null,
                ];
            }

            $start = $now->startOfDay();
            $end = $now->endOfDay();
            $result = $this->calendar->listEvents($account, 'primary', [
                'time_min' => $start->utc()->toIso8601String(),
                'time_max' => $end->utc()->toIso8601String(),
                'max_results' => 8,
                'order_by' => 'startTime',
                'single_events' => true,
            ]);

            $events = [];

            foreach (array_slice($result['events'] ?? [], 0, 8) as $event) {
                if (! is_array($event)) {
                    continue;
                }

                $events[] = [
                    'id' => (string) ($event['id'] ?? ''),
                    'title' => (string) ($event['title'] ?? 'Событие'),
                    'when_label' => $this->eventWhenLabel($event, $now),
                ];
            }

            return [
                'events' => $events,
                'hint' => $events === [] ? 'На сегодня в календаре нет событий.' : null,
                'error' => null,
            ];
        } catch (\Throwable) {
            return [
                'events' => [],
                'hint' => null,
                'error' => 'Календарь сейчас недоступен.',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function eventWhenLabel(array $event, CarbonImmutable $now): string
    {
        $start = (string) ($event['start'] ?? '');

        if ($start === '') {
            return '';
        }

        try {
            $at = CarbonImmutable::parse($start)->setTimezone($now->getTimezone()->getName());

            if (! empty($event['all_day'])) {
                return 'Весь день';
            }

            return $at->format('H:i');
        } catch (\Throwable) {
            return $start;
        }
    }

    private function summary(int $dueTasks, int $reminders, int $unread, int $events): string
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

        if ($events > 0) {
            $parts[] = $events.' в календаре';
        }

        if ($parts === []) {
            return 'На сегодня нет срочных пунктов. Можно спросить LAVR, что главное.';
        }

        return 'В фокусе: '.implode(', ', $parts).'.';
    }
}
