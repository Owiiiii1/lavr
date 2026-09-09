<?php

namespace Database\Factories;

use App\Enums\MeetingAnalysisStatus;
use App\Enums\MeetingSourceType;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Meeting>
 */
class MeetingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'source_type' => MeetingSourceType::ManualText,
            'status' => MeetingStatus::Ready,
            'analysis_status' => MeetingAnalysisStatus::Pending,
            'timezone' => 'Europe/Kyiv',
        ];
    }
}
