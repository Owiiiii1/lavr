<?php

namespace App\Services\LeadershipReview;

use App\Enums\LeadershipReviewType;
use App\Models\User;
use App\Models\UserProductivitySetting;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Support\Facades\Schema;

final class LeadershipReviewDispatchService
{
    public function __construct(
        private readonly LeadershipReviewGenerator $generator,
    ) {}

    public function dispatchDue(int $limit = 40): int
    {
        if (! Schema::hasTable('leadership_reviews') || ! Schema::hasTable('user_productivity_settings')) {
            return 0;
        }

        $now = CarbonImmutable::now('UTC');
        $sent = 0;
        $rows = UserProductivitySetting::query()
            ->with('user')
            ->where('leadership_review_enabled', true)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($rows as $settings) {
            $user = $settings->user;
            if (! $user instanceof User || ! $user->isActive()) {
                continue;
            }

            if (! $this->isDue($settings, $user, $now)) {
                continue;
            }

            $period = LeadershipReviewPeriod::fromInput(
                ['period' => '30'],
                $now,
                (string) ($user->timezone ?: 'UTC'),
            );

            $this->generator->generate(
                $user,
                LeadershipReviewType::Owner,
                $period,
                'scheduled',
                null,
                null,
                null,
                [
                    'telegram' => (bool) $settings->leadership_review_telegram,
                    'inbox' => (bool) $settings->leadership_review_inbox,
                ],
            );

            $settings->forceFill(['last_leadership_review_at' => $now->utc()])->save();
            $sent++;
        }

        return $sent;
    }

    public function isDue(UserProductivitySetting $settings, User $user, CarbonImmutable $now): bool
    {
        if (! $settings->leadership_review_enabled) {
            return false;
        }

        $timezone = (string) ($user->timezone ?: 'UTC');
        try {
            new DateTimeZone($timezone);
            $local = $now->utc()->setTimezone($timezone);
        } catch (Exception) {
            $local = $now->utc();
        }

        $weekday = (int) ($settings->leadership_review_weekday ?: config('leadership_review.weekly_weekday', 1));
        if ((int) $local->isoWeekday() !== $weekday) {
            return false;
        }

        $time = (string) ($settings->leadership_review_local_time ?: config('leadership_review.weekly_local_time', '09:00'));
        if ($local->format('H:i') < $time) {
            return false;
        }

        $last = $settings->last_leadership_review_at;
        if ($last instanceof CarbonImmutable && $last->utc()->isoWeek() === $local->utc()->isoWeek() && $last->utc()->year === $local->utc()->year) {
            return false;
        }

        return true;
    }
}
