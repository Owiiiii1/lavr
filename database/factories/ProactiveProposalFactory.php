<?php

namespace Database\Factories;

use App\Enums\ExternalActionLevel;
use App\Enums\OperationalSeverity;
use App\Enums\ProactiveProposalStatus;
use App\Enums\ProactiveProposalType;
use App\Models\ProactiveProposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProactiveProposal>
 */
class ProactiveProposalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'proposal_type' => ProactiveProposalType::RemindPerson,
            'title' => 'Remind about overdue commitment',
            'rationale' => 'The deadline passed.',
            'action_payload_json' => ['action' => 'remind_person'],
            'status' => ProactiveProposalStatus::Pending,
            'requires_confirmation' => true,
            'policy_level' => ExternalActionLevel::Suggest,
            'fingerprint' => hash('sha256', 'factory:'.Str::uuid()->toString()),
            'severity' => OperationalSeverity::High,
        ];
    }
}
