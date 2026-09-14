<?php

namespace Database\Factories;

use App\Enums\LeadershipReviewStatus;
use App\Enums\LeadershipReviewType;
use App\Models\LeadershipReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LeadershipReview>
 */
class LeadershipReviewFactory extends Factory
{
    public function definition(): array
    {
        $end = now('UTC');
        $start = $end->copy()->subDays(30);

        return [
            'user_id' => User::factory(),
            'review_type' => LeadershipReviewType::Owner,
            'period_start' => $start,
            'period_end' => $end,
            'status' => LeadershipReviewStatus::Ready,
            'summary' => 'Покриття власниками action items 80%.',
            'metrics_json' => [
                'commitments_total' => 0,
                'owner_coverage_pct' => 80,
                'deadline_coverage_pct' => 70,
            ],
            'findings_json' => [
                'findings' => [],
                'strengths' => [],
                'attention' => [],
                'trends' => [],
            ],
            'source_snapshot_json' => [
                'sources_attempted' => 2,
                'sources_succeeded' => 2,
                'data_through' => now()->toIso8601String(),
            ],
            'generated_at' => now(),
            'generated_by' => 'deterministic',
            'origin' => 'manual',
            'run_key' => 'leadership_review:factory:'.Str::uuid()->toString(),
            'timezone' => 'Europe/Rome',
            'locale' => 'uk',
        ];
    }
}
