<?php

namespace App\Services\Commitments;

final class CommitmentConfig
{
    public static function dueSoonHours(): int
    {
        return max(1, (int) config('commitments.due_soon_hours', 48));
    }

    public static function maxExcerptChars(): int
    {
        return max(40, (int) config('commitments.max_excerpt_chars', 280));
    }

    public static function titleMax(): int
    {
        return max(20, (int) config('commitments.title_max', 120));
    }

    public static function confirmedRecentLimit(): int
    {
        return max(1, (int) config('commitments.confirmed_recent_limit', 8));
    }
}
