<?php

namespace App\Services\Productivity;

use App\Models\User;
use App\Models\UserProductivitySetting;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;

final class ProductivitySettingsService
{
    public function for(User $user): UserProductivitySetting
    {
        $existing = $user->relationLoaded('productivitySetting')
            ? $user->productivitySetting
            : UserProductivitySetting::query()->where('user_id', $user->id)->first();

        if ($existing instanceof UserProductivitySetting) {
            return $existing;
        }

        $settings = new UserProductivitySetting;
        $settings->forceFill([
            'user_id' => $user->id,
            'daily_brief_enabled' => false,
            'daily_brief_local_time' => (string) config('productivity.briefs.daily_local_time', '08:00'),
            'evening_review_enabled' => false,
            'evening_review_local_time' => (string) config('productivity.briefs.evening_local_time', '20:00'),
            'weekly_review_enabled' => false,
            'weekly_review_weekday' => (int) config('productivity.briefs.weekly_weekday', 7),
            'weekly_review_local_time' => (string) config('productivity.briefs.weekly_local_time', '18:00'),
            'proactive_enabled' => false,
            'morning_brief_enabled' => true,
            'morning_brief_local_time' => (string) config('executive_brief.morning_local_time', '08:30'),
            'morning_brief_telegram' => true,
            'morning_brief_inbox' => true,
            'morning_brief_weekends' => false,
            'leadership_review_enabled' => true,
            'leadership_review_weekday' => (int) config('leadership_review.weekly_weekday', 1),
            'leadership_review_local_time' => (string) config('leadership_review.weekly_local_time', '09:00'),
            'leadership_review_telegram' => true,
            'leadership_review_inbox' => true,
        ]);

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes): UserProductivitySetting
    {
        $settings = UserProductivitySetting::query()->firstOrNew(['user_id' => $user->id]);
        $defaults = $this->for($user);

        $settings->forceFill([
            'user_id' => $user->id,
            'daily_brief_enabled' => (bool) ($attributes['daily_brief_enabled'] ?? $settings->daily_brief_enabled ?? $defaults->daily_brief_enabled),
            'daily_brief_local_time' => $this->normalizeTime($attributes['daily_brief_local_time'] ?? $settings->daily_brief_local_time ?? $defaults->daily_brief_local_time),
            'evening_review_enabled' => (bool) ($attributes['evening_review_enabled'] ?? $settings->evening_review_enabled ?? $defaults->evening_review_enabled),
            'evening_review_local_time' => $this->normalizeTime($attributes['evening_review_local_time'] ?? $settings->evening_review_local_time ?? $defaults->evening_review_local_time),
            'weekly_review_enabled' => (bool) ($attributes['weekly_review_enabled'] ?? $settings->weekly_review_enabled ?? $defaults->weekly_review_enabled),
            'weekly_review_weekday' => $this->normalizeWeekday($attributes['weekly_review_weekday'] ?? $settings->weekly_review_weekday ?? $defaults->weekly_review_weekday),
            'weekly_review_local_time' => $this->normalizeTime($attributes['weekly_review_local_time'] ?? $settings->weekly_review_local_time ?? $defaults->weekly_review_local_time),
            'proactive_enabled' => (bool) ($attributes['proactive_enabled'] ?? $settings->proactive_enabled ?? $defaults->proactive_enabled),
            'morning_brief_enabled' => (bool) ($attributes['morning_brief_enabled'] ?? $settings->morning_brief_enabled ?? $defaults->morning_brief_enabled),
            'morning_brief_local_time' => $this->normalizeTime($attributes['morning_brief_local_time'] ?? $settings->morning_brief_local_time ?? $defaults->morning_brief_local_time),
            'morning_brief_telegram' => (bool) ($attributes['morning_brief_telegram'] ?? $settings->morning_brief_telegram ?? $defaults->morning_brief_telegram),
            'morning_brief_inbox' => (bool) ($attributes['morning_brief_inbox'] ?? $settings->morning_brief_inbox ?? $defaults->morning_brief_inbox),
            'morning_brief_weekends' => (bool) ($attributes['morning_brief_weekends'] ?? $settings->morning_brief_weekends ?? $defaults->morning_brief_weekends),
            'leadership_review_enabled' => (bool) ($attributes['leadership_review_enabled'] ?? $settings->leadership_review_enabled ?? $defaults->leadership_review_enabled),
            'leadership_review_weekday' => $this->normalizeWeekday($attributes['leadership_review_weekday'] ?? $settings->leadership_review_weekday ?? $defaults->leadership_review_weekday),
            'leadership_review_local_time' => $this->normalizeTime($attributes['leadership_review_local_time'] ?? $settings->leadership_review_local_time ?? $defaults->leadership_review_local_time),
            'leadership_review_telegram' => (bool) ($attributes['leadership_review_telegram'] ?? $settings->leadership_review_telegram ?? $defaults->leadership_review_telegram),
            'leadership_review_inbox' => (bool) ($attributes['leadership_review_inbox'] ?? $settings->leadership_review_inbox ?? $defaults->leadership_review_inbox),
        ]);
        $settings->save();

        return $settings;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(User $user): array
    {
        $settings = $this->for($user);

        return [
            'daily_brief_enabled' => (bool) $settings->daily_brief_enabled,
            'daily_brief_local_time' => (string) $settings->daily_brief_local_time,
            'evening_review_enabled' => (bool) $settings->evening_review_enabled,
            'evening_review_local_time' => (string) $settings->evening_review_local_time,
            'weekly_review_enabled' => (bool) $settings->weekly_review_enabled,
            'weekly_review_weekday' => (int) $settings->weekly_review_weekday,
            'weekly_review_local_time' => (string) $settings->weekly_review_local_time,
            'proactive_enabled' => (bool) $settings->proactive_enabled,
            'morning_brief_enabled' => (bool) $settings->morning_brief_enabled,
            'morning_brief_local_time' => (string) ($settings->morning_brief_local_time ?: '08:30'),
            'morning_brief_telegram' => (bool) $settings->morning_brief_telegram,
            'morning_brief_inbox' => (bool) $settings->morning_brief_inbox,
            'morning_brief_weekends' => (bool) $settings->morning_brief_weekends,
            'leadership_review_enabled' => (bool) $settings->leadership_review_enabled,
            'leadership_review_weekday' => (int) ($settings->leadership_review_weekday ?: 1),
            'leadership_review_local_time' => (string) ($settings->leadership_review_local_time ?: '09:00'),
            'leadership_review_telegram' => (bool) $settings->leadership_review_telegram,
            'leadership_review_inbox' => (bool) $settings->leadership_review_inbox,
        ];
    }

    public function isDue(
        UserProductivitySetting $settings,
        string $mode,
        User $user,
        CarbonImmutable $now,
    ): bool {
        $timezone = (string) ($user->timezone ?: 'UTC');

        try {
            new DateTimeZone($timezone);
            $local = $now->utc()->setTimezone($timezone);
        } catch (Exception) {
            $local = $now->utc();
        }

        return match ($mode) {
            'daily' => $this->clockDue(
                (bool) $settings->daily_brief_enabled,
                (string) $settings->daily_brief_local_time,
                $settings->last_daily_brief_at,
                $local,
            ),
            'evening' => $this->clockDue(
                (bool) $settings->evening_review_enabled,
                (string) $settings->evening_review_local_time,
                $settings->last_evening_review_at,
                $local,
            ),
            'weekly' => $this->weeklyDue($settings, $local),
            default => false,
        };
    }

    private function clockDue(bool $enabled, string $time, mixed $lastAt, CarbonImmutable $localNow): bool
    {
        if (! $enabled) {
            return false;
        }

        if ($localNow->format('H:i') < $time) {
            return false;
        }

        if ($lastAt instanceof CarbonImmutable && $lastAt->utc()->setTimezone($localNow->timezoneName)->toDateString() === $localNow->toDateString()) {
            return false;
        }

        return true;
    }

    private function weeklyDue(UserProductivitySetting $settings, CarbonImmutable $localNow): bool
    {
        if (! $settings->weekly_review_enabled) {
            return false;
        }

        $weekday = (int) $settings->weekly_review_weekday;
        $isoDay = (int) $localNow->isoWeekday();

        if ($isoDay !== $weekday) {
            return false;
        }

        if ($localNow->format('H:i') < (string) $settings->weekly_review_local_time) {
            return false;
        }

        $last = $settings->last_weekly_review_at;

        if ($last instanceof CarbonImmutable && $last->utc()->isoWeek() === $localNow->utc()->isoWeek() && $last->utc()->year === $localNow->utc()->year) {
            return false;
        }

        return true;
    }

    private function normalizeTime(mixed $value): string
    {
        $raw = is_string($value) ? trim($value) : '';

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $raw) !== 1) {
            return '08:00';
        }

        return $raw;
    }

    private function normalizeWeekday(mixed $value): int
    {
        $day = (int) $value;

        if ($day < 1 || $day > 7) {
            return 7;
        }

        return $day;
    }
}
