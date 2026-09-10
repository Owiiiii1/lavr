<?php

namespace App\Services\Workspace\Presentation;

use App\Enums\ScheduledReportPeriodMode;
use App\Enums\ScheduledReportStatus;
use App\Enums\ScheduledReportType;
use App\Models\ScheduledReport;
use App\Services\Reports\ScheduledReportSchedule;

final class HumanScheduledReportDescription
{
    public static function sentence(ScheduledReport $report): string
    {
        $when = 'Каждый день в '.self::clock($report->local_time);
        $sources = self::sourceLabels($report);

        return $when.' — '.self::purpose($report).($sources !== [] ? '. Источники: '.implode(' · ', $sources) : '.');
    }

    /**
     * @return list<string>
     */
    public static function sourceLabels(ScheduledReport $report): array
    {
        $labels = [];
        $sources = is_array($report->sources) ? $report->sources : [];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $type = (string) ($source['type'] ?? '');
            $labels[] = match ($type) {
                'tasks' => 'Задачи',
                'reminders' => 'Напоминания',
                'projects' => 'Проекты',
                'synthesis' => 'Планы',
                'google_calendar' => self::calendarLabel($source),
                'gmail' => 'Gmail',
                'telegram_groups' => 'Telegram-группы',
                'commitments' => 'Обязательства',
                'notifications' => 'Уведомления',
                default => '',
            };
        }

        return array_values(array_filter($labels, static fn (string $label): bool => $label !== ''));
    }

    public static function purpose(ScheduledReport $report): string
    {
        return match ($report->report_type) {
            ScheduledReportType::DailyPlan => 'планы на сегодня',
            ScheduledReportType::TomorrowPlan => 'планы на завтра',
            ScheduledReportType::MailGroupsDigest => 'новые письма и сводка по группам',
            ScheduledReportType::CustomComposite => 'составной отчёт',
        };
    }

    public static function periodLabel(ScheduledReportPeriodMode $mode): string
    {
        return match ($mode) {
            ScheduledReportPeriodMode::Today => 'Сегодня',
            ScheduledReportPeriodMode::Tomorrow => 'Завтра',
            ScheduledReportPeriodMode::SincePreviousReport => 'С прошлого отчёта',
            ScheduledReportPeriodMode::Last24h => 'Последние 24 часа',
        };
    }

    public static function statusLabel(ScheduledReportStatus $status): string
    {
        return match ($status) {
            ScheduledReportStatus::Active => 'Активен',
            ScheduledReportStatus::Paused => 'На паузе',
            ScheduledReportStatus::Cancelled => 'Отменён',
        };
    }

    public static function scheduleLabel(ScheduledReport $report): string
    {
        return 'Каждый день · '.self::clock($report->local_time);
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function calendarLabel(array $source): string
    {
        $names = $source['calendar_names'] ?? [];
        if (is_array($names) && $names !== []) {
            return 'Календарь · '.implode(' · ', array_map(static fn ($name): string => (string) $name, $names));
        }

        $scope = (string) ($source['calendar_scope'] ?? 'all_relevant');

        return $scope === 'all_relevant' ? 'Календарь · Семья' : 'Календарь';
    }

    private static function clock(string $time): string
    {
        $normalized = ScheduledReportSchedule::normalizeTime($time) ?? '08:00';
        $parts = explode(':', $normalized);

        return ((int) ($parts[0] ?? 8)).':'.str_pad((string) ((int) ($parts[1] ?? 0)), 2, '0', STR_PAD_LEFT);
    }
}
