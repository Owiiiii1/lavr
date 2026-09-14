<?php

namespace App\Services\Validation;

use App\Enums\CommitmentEffectiveStatus;
use App\Enums\CommitmentLifecycleStatus;
use App\Enums\CommitmentSourceType;
use App\Enums\OperationalEventStatus;
use App\Enums\OperationalEventType;
use App\Enums\OperationalSeverity;
use App\Enums\ProactiveProposalStatus;
use App\Enums\ProactiveProposalType;
use App\Enums\SourceItemType;
use App\Models\Commitment;
use App\Models\Meeting;
use App\Models\OperationalEvent;
use App\Models\ProactiveProposal;
use App\Models\SourceItem;
use App\Models\User;
use App\Models\ValidationBatch;
use App\Models\ValidationBatchItem;
use App\Services\Directory\DirectoryService;
use App\Services\Projects\ProjectService;
use Illuminate\Support\Str;

final class ValidationSeedService
{
    public function __construct(
        private readonly DirectoryService $directory,
        private readonly ProjectService $projects,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function seed(User $user, bool $apply): array
    {
        $marker = 'val_'.Str::lower(Str::random(10));
        $preview = [
            'marker' => $marker,
            'dry_run' => ! $apply,
            'will_create' => [
                'person' => '[VAL] Serhii',
                'project' => '[VAL] Chicago',
                'meeting' => '[VAL] Budget review',
                'commitment' => '[VAL] Final budget',
                'source_item' => 1,
                'operational_event' => 1,
                'proactive_proposal' => 1,
            ],
        ];

        if (! $apply) {
            return $preview;
        }

        $batch = ValidationBatch::query()->create([
            'user_id' => $user->id,
            'marker' => $marker,
            'notes' => 'synthetic validation',
        ]);

        $person = $this->directory->createPerson($user, [
            'first_name' => 'Serhii',
            'display_name' => '[VAL] Serhii',
        ]);
        $project = $this->projects->create($user, '[VAL] Chicago '.Str::random(4));
        $this->directory->attachPersonToProject($user, $project, $person, 'owner');

        $meeting = Meeting::factory()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'title' => '[VAL] Budget review',
        ]);

        $commitment = Commitment::factory()->create([
            'user_id' => $user->id,
            'person_id' => $person->id,
            'project_id' => $project->id,
            'meeting_id' => $meeting->id,
            'title' => '[VAL] Final budget',
            'status' => CommitmentEffectiveStatus::Open,
            'lifecycle_status' => CommitmentLifecycleStatus::Open,
            'source_type' => CommitmentSourceType::Manual,
        ]);

        $item = SourceItem::query()->create([
            'user_id' => $user->id,
            'source_type' => SourceItemType::TelegramMessage,
            'source_instance' => 'validation:'.$marker,
            'external_id' => 'val-'.$marker,
            'occurred_at' => now(),
            'person_id' => $person->id,
            'project_id' => $project->id,
            'snippet' => 'synthetic progress',
        ]);

        $event = OperationalEvent::query()->create([
            'user_id' => $user->id,
            'event_type' => OperationalEventType::CommitmentDetected,
            'occurred_at' => now(),
            'source_type' => 'validation',
            'source_id' => $item->id,
            'person_id' => $person->id,
            'project_id' => $project->id,
            'meeting_id' => $meeting->id,
            'commitment_id' => $commitment->id,
            'severity' => OperationalSeverity::Low,
            'fingerprint' => hash('sha256', $marker.'detected'),
            'payload_json' => ['batch' => $marker],
            'status' => OperationalEventStatus::Observed,
            'confidence' => 80,
            'evidence_pointer' => 'source_item:'.$item->id,
        ]);

        $proposal = ProactiveProposal::query()->create([
            'user_id' => $user->id,
            'operational_event_id' => $event->id,
            'person_id' => $person->id,
            'project_id' => $project->id,
            'commitment_id' => $commitment->id,
            'meeting_id' => $meeting->id,
            'proposal_type' => ProactiveProposalType::ReviewDetectedCommitment,
            'title' => '[VAL] Review detected commitment',
            'rationale' => 'Synthetic validation item.',
            'action_payload_json' => ['batch' => $marker],
            'status' => ProactiveProposalStatus::Pending,
            'fingerprint' => hash('sha256', $marker.'proposal'),
        ]);

        foreach ([
            ['person', $person->id],
            ['project', $project->id],
            ['meeting', $meeting->id],
            ['commitment', $commitment->id],
            ['source_item', $item->id],
            ['operational_event', $event->id],
            ['proactive_proposal', $proposal->id],
        ] as [$type, $id]) {
            ValidationBatchItem::query()->create([
                'validation_batch_id' => $batch->id,
                'entity_type' => $type,
                'entity_id' => $id,
            ]);
        }

        $preview['dry_run'] = false;
        $preview['batch_id'] = $batch->id;

        return $preview;
    }
}
