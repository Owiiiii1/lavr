<?php

namespace App\Services\Reports;

use App\Enums\ScheduledReportPeriodMode;
use App\Enums\ScheduledReportType;
use App\Models\User;
use App\Services\Watchers\ProactiveCheckIntent;

final class ScheduledReportIntent
{
    public static function matches(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        if ($normalized === '' || ProactiveCheckIntent::userSelfReminder($normalized)) {
            return false;
        }

        if (ProactiveCheckIntent::isGmailEventMonitoring($text)) {
            return false;
        }

        $periodic = preg_match('/кажд|по утрам|по вечерам|every (?:morning|evening|day)|daily/u', $normalized) === 1;
        $asksReport = preg_match('/отч[её]т|сводк|дай план|присыл\w*.{0,40}план|планы на (?:завтра|сегодня|текущ)|итог/u', $normalized) === 1;
        $periodicMailOrGroups = preg_match('/почт|gmail|inbox|письм|групп/u', $normalized) === 1
            && preg_match('/проверяй|проверь|посмотри|сообщай|присыл|рассказ|дай |сводк|что нового/u', $normalized) === 1;

        return $periodic && ($asksReport || $periodicMailOrGroups);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function fromInbound(string $text, User $user): ?array
    {
        if (! self::matches($text)) {
            return null;
        }

        $normalized = mb_strtolower($text);
        $timezone = ScheduledReportSchedule::timezoneFor($user);
        $localTime = ScheduledReportSchedule::parseLocalTime($text) ?? self::impliedTime($normalized);
        $type = self::inferType($normalized);
        $period = self::inferPeriod($normalized, $type);
        $sources = self::defaultSources($type, $normalized);

        return [
            'name' => self::defaultName($type),
            'report_type' => $type->value,
            'period_mode' => $period->value,
            'local_time' => $localTime,
            'timezone' => $timezone,
            'schedule_kind' => 'daily_local',
            'sources' => $sources,
            'delivery' => [
                'telegram' => true,
                'web_notification' => true,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function defaultSources(ScheduledReportType $type, string $normalized = ''): array
    {
        $wantsFamily = preg_match('/семь|семья|family|связанн\w*\s+календар/u', $normalized) === 1;

        return match ($type) {
            ScheduledReportType::MailGroupsDigest => [
                ['type' => 'gmail', 'mode' => 'new_since_last_report'],
                ['type' => 'telegram_groups', 'mode' => 'summary_since_last_report'],
            ],
            ScheduledReportType::DailyPlan, ScheduledReportType::TomorrowPlan => [
                ['type' => 'tasks'],
                ['type' => 'reminders'],
                ['type' => 'commitments'],
                ['type' => 'synthesis'],
                array_filter([
                    'type' => 'google_calendar',
                    'calendar_scope' => 'all_relevant',
                    'calendar_names' => $wantsFamily ? ['Семья'] : null,
                ]),
            ],
            ScheduledReportType::CustomComposite => [
                ['type' => 'tasks'],
            ],
        };
    }

    public static function defaultName(ScheduledReportType $type): string
    {
        return match ($type) {
            ScheduledReportType::DailyPlan => 'Планы на сегодня',
            ScheduledReportType::TomorrowPlan => 'Планы на завтра',
            ScheduledReportType::MailGroupsDigest => 'Почта и группы',
            ScheduledReportType::CustomComposite => 'Отчёт',
        };
    }

    public static function inferType(string $normalized): ScheduledReportType
    {
        $mail = preg_match('/почт|письм|gmail|inbox/u', $normalized) === 1;
        $groups = preg_match('/групп/u', $normalized) === 1;
        $tomorrow = preg_match('/завтра/u', $normalized) === 1;
        $today = preg_match('/сегодня|текущ(?:ий|его)\s+день|на день/u', $normalized) === 1;

        if ($mail || $groups) {
            return ScheduledReportType::MailGroupsDigest;
        }

        if ($tomorrow && ! $today) {
            return ScheduledReportType::TomorrowPlan;
        }

        if ($today) {
            return ScheduledReportType::DailyPlan;
        }

        if ($tomorrow) {
            return ScheduledReportType::TomorrowPlan;
        }

        return ScheduledReportType::CustomComposite;
    }

    public static function inferPeriod(string $normalized, ScheduledReportType $type): ScheduledReportPeriodMode
    {
        return match ($type) {
            ScheduledReportType::TomorrowPlan => ScheduledReportPeriodMode::Tomorrow,
            ScheduledReportType::DailyPlan => ScheduledReportPeriodMode::Today,
            ScheduledReportType::MailGroupsDigest => ScheduledReportPeriodMode::SincePreviousReport,
            ScheduledReportType::CustomComposite => preg_match('/завтра/u', $normalized) === 1
                ? ScheduledReportPeriodMode::Tomorrow
                : ScheduledReportPeriodMode::Today,
        };
    }

    private static function impliedTime(string $normalized): string
    {
        if (preg_match('/вечер/u', $normalized) === 1) {
            return '22:00';
        }

        if (preg_match('/утр/u', $normalized) === 1) {
            return '08:00';
        }

        return '08:00';
    }
}
