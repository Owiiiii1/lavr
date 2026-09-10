<?php

namespace Database\Factories;

use App\Enums\AutomationRunOutcome;
use App\Enums\AutomationType;
use App\Models\AutomationRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AutomationRun>
 */
class AutomationRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'automation_type' => AutomationType::ScheduledReport,
            'automation_id' => 1,
            'run_key' => 'scheduled_report:1:'.Str::uuid()->toString(),
            'status' => AutomationRunOutcome::Success,
            'attempt' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
