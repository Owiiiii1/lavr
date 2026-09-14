<?php

namespace App\Services\LeadershipReview;

use App\Enums\OwnerLocale;

final class LeadershipReviewCopy
{
    public static function insufficientTrend(OwnerLocale $locale): string
    {
        return match ($locale) {
            OwnerLocale::En => 'Insufficient data for trend.',
            OwnerLocale::Ru => 'Недостаточно данных для тренда.',
            default => 'Недостатньо даних для тренду.',
        };
    }

    public static function dataThrough(OwnerLocale $locale, string $timestamp): string
    {
        return match ($locale) {
            OwnerLocale::En => 'Data through '.$timestamp.'.',
            OwnerLocale::Ru => 'Данные по '.$timestamp.'.',
            default => 'Дані станом на '.$timestamp.'.',
        };
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    public static function emptySummary(OwnerLocale $locale, array $metrics): string
    {
        $meetings = (int) ($metrics['meetings_total'] ?? 0);
        $commitments = (int) ($metrics['commitments_total'] ?? 0);

        return match ($locale) {
            OwnerLocale::En => 'Not enough structured process signals in this period ('.$meetings.' meetings, '.$commitments.' commitments).',
            OwnerLocale::Ru => 'В этом периоде мало структурированных процессных сигналов ('.$meetings.' встреч, '.$commitments.' обязательств).',
            default => 'У цьому періоді мало структурованих процесних сигналів ('.$meetings.' зустрічей, '.$commitments.' зобов’язань).',
        };
    }

    public static function coverageImproved(OwnerLocale $locale, string $label, int $from, int $to): string
    {
        return match ($locale) {
            OwnerLocale::En => $label.' improved from '.$from.'% to '.$to.'%.',
            OwnerLocale::Ru => $label.' улучшилось с '.$from.'% до '.$to.'%.',
            default => $label.' покращилось з '.$from.'% до '.$to.'%.',
        };
    }

    public static function coverageWorsened(OwnerLocale $locale, string $label, int $from, int $to): string
    {
        return match ($locale) {
            OwnerLocale::En => $label.' declined from '.$from.'% to '.$to.'%.',
            OwnerLocale::Ru => $label.' снизилось с '.$from.'% до '.$to.'%.',
            default => $label.' знизилось з '.$from.'% до '.$to.'%.',
        };
    }

    public static function deadlineLabel(OwnerLocale $locale): string
    {
        return match ($locale) {
            OwnerLocale::En => 'Deadline coverage',
            OwnerLocale::Ru => 'Покрытие дедлайнами',
            default => 'Покриття дедлайнами',
        };
    }

    public static function ownerLabel(OwnerLocale $locale): string
    {
        return match ($locale) {
            OwnerLocale::En => 'Owner coverage',
            OwnerLocale::Ru => 'Покрытие владельцами',
            default => 'Покриття власниками',
        };
    }
}
