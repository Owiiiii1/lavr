<?php

namespace App\Services\LeadershipReview;

use App\Enums\LeadershipReviewType;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;

final class LeadershipReviewPeriod
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $timezone,
        public readonly int $days,
    ) {}

    /**
     * @param  array{period?: string, from?: string, to?: string}  $input
     */
    public static function fromInput(array $input, CarbonImmutable $now, string $timezone): self
    {
        $tz = self::safeTimezone($timezone);
        $local = $now->utc()->setTimezone($tz);
        $preset = (string) ($input['period'] ?? '30');
        $from = trim((string) ($input['from'] ?? ''));
        $to = trim((string) ($input['to'] ?? ''));

        if ($preset === 'custom' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $start = CarbonImmutable::parse($from, $tz)->startOfDay();
            $end = CarbonImmutable::parse($to, $tz)->endOfDay();
            if ($end->lessThan($start)) {
                [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
            }
        } else {
            $days = $preset === '7' ? 7 : 30;
            $end = $local->endOfDay();
            $start = $end->subDays($days - 1)->startOfDay();
        }

        $days = max(1, $start->diffInDays($end) + 1);

        return new self($start->utc(), $end->utc(), $tz, $days);
    }

    public function previous(): self
    {
        $tz = $this->timezone;
        $localStart = $this->start->utc()->setTimezone($tz);
        $previousEnd = $localStart->subSecond();
        $previousStart = $previousEnd->subDays($this->days - 1)->startOfDay();

        return new self($previousStart->utc(), $previousEnd->utc(), $tz, $this->days);
    }

    public static function safeTimezone(string $timezone): string
    {
        try {
            new DateTimeZone($timezone);

            return $timezone;
        } catch (Exception) {
            return 'UTC';
        }
    }

    public static function typeFrom(string $value): LeadershipReviewType
    {
        return LeadershipReviewType::tryFrom($value) ?? LeadershipReviewType::Owner;
    }
}
