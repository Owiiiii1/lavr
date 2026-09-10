<?php

namespace App\Services\ExecutiveBrief;

use App\Enums\ExecutiveBriefType;
use App\Models\User;
use App\Models\UserProductivitySetting;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Support\Facades\Schema;

final class ExecutiveBriefDispatchService
{
    public function __construct(
        private readonly ExecutiveBriefGenerator $generator,
    ) {}

    public function dispatchDue(int $limit = 40): int
    {
        if (! Schema::hasTable('executive_briefs') || ! Schema::hasTable('user_productivity_settings')) {
            return 0;
        }

        $now = CarbonImmutable::now('UTC');
        $sent = 0;
        $rows = UserProductivitySetting::query()
            ->with('user')
            ->where('morning_brief_enabled', true)
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

            $this->generator->generate(
                $user,
                ExecutiveBriefType::Morning,
                $now,
                'scheduled',
                null,
                [
                    'telegram' => (bool) $settings->morning_brief_telegram,
                    'inbox' => (bool) $settings->morning_brief_inbox,
                ],
            );

            $settings->forceFill(['last_morning_brief_at' => $now->utc()])->save();
            $sent++;
        }

        return $sent;
    }

    public function isDue(UserProductivitySetting $settings, User $user, CarbonImmutable $now): bool
    {
        if (! $settings->morning_brief_enabled) {
            return false;
        }

        $timezone = (string) ($user->timezone ?: 'UTC');
        try {
            new DateTimeZone($timezone);
            $local = $now->utc()->setTimezone($timezone);
        } catch (Exception) {
            $local = $now->utc();
        }

        if (! (bool) $settings->morning_brief_weekends && $local->isWeekend()) {
            return false;
        }

        $time = (string) ($settings->morning_brief_local_time ?: config('executive_brief.morning_local_time', '08:30'));
        if ($local->format('H:i') < $time) {
            return false;
        }

        $last = $settings->last_morning_brief_at;
        if ($last instanceof CarbonImmutable && $last->utc()->setTimezone($local->timezoneName)->toDateString() === $local->toDateString()) {
            return false;
        }

        return true;
    }
}
