<?php

namespace Database\Factories;

use App\Enums\CommitmentConfidence;
use App\Enums\CommitmentEffectiveStatus;
use App\Enums\CommitmentLifecycleStatus;
use App\Enums\CommitmentSourceType;
use App\Models\Commitment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Commitment>
 */
class CommitmentFactory extends Factory
{
    public function definition(): array
    {
        $fingerprint = hash('sha256', 'factory:'.Str::uuid()->toString());

        return [
            'user_id' => User::factory(),
            'title' => 'Send final budget',
            'expected_result' => 'Final Chicago budget file',
            'status' => CommitmentEffectiveStatus::Open,
            'lifecycle_status' => CommitmentLifecycleStatus::Open,
            'confidence' => CommitmentConfidence::High,
            'source_type' => CommitmentSourceType::Manual,
            'fingerprint' => $fingerprint,
            'unresolved_person' => false,
            'detected_at' => now(),
            'confirmed_at' => now(),
        ];
    }

    public function detected(): self
    {
        return $this->state(fn (): array => [
            'status' => CommitmentEffectiveStatus::Detected,
            'lifecycle_status' => CommitmentLifecycleStatus::Detected,
            'confirmed_at' => null,
            'source_type' => CommitmentSourceType::Meeting,
        ]);
    }
}
