<?php

namespace Database\Factories;

use App\Enums\ExecutiveBriefStatus;
use App\Enums\ExecutiveBriefType;
use App\Models\ExecutiveBrief;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ExecutiveBrief>
 */
class ExecutiveBriefFactory extends Factory
{
    public function definition(): array
    {
        $date = now('Europe/Rome')->toDateString();

        return [
            'user_id' => User::factory(),
            'brief_type' => ExecutiveBriefType::Morning,
            'origin' => 'scheduled',
            'period_start' => now()->subDay(),
            'period_end' => now()->endOfDay(),
            'generated_for' => $date,
            'timezone' => 'Europe/Rome',
            'locale' => 'uk',
            'status' => ExecutiveBriefStatus::Ready,
            'priority_score' => 40,
            'summary' => 'На сьогодні немає критичних питань.',
            'sections_json' => ['attention' => [], 'today' => []],
            'source_snapshot_json' => ['sources_attempted' => 0, 'sources_succeeded' => 0],
            'run_key' => 'executive_brief:factory:morning:'.$date.':'.Str::uuid()->toString(),
            'generated_at' => now(),
        ];
    }
}
