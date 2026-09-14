<?php

namespace Database\Factories;

use App\Enums\OperationalEventStatus;
use App\Enums\OperationalEventType;
use App\Enums\OperationalSeverity;
use App\Models\OperationalEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OperationalEvent>
 */
class OperationalEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_type' => OperationalEventType::CommitmentOverdue,
            'occurred_at' => now(),
            'source_type' => 'commitment',
            'severity' => OperationalSeverity::High,
            'fingerprint' => hash('sha256', 'factory:'.Str::uuid()->toString()),
            'payload_json' => ['rationale' => 'factory'],
            'status' => OperationalEventStatus::Actionable,
            'confidence' => 'high',
        ];
    }
}
