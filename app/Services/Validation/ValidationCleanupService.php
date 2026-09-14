<?php

namespace App\Services\Validation;

use App\Models\Commitment;
use App\Models\CommitmentEvidence;
use App\Models\CommitmentStatusHistory;
use App\Models\Meeting;
use App\Models\OperationalEvent;
use App\Models\Organization;
use App\Models\Person;
use App\Models\ProactiveProposal;
use App\Models\ProactiveProposalAudit;
use App\Models\Project;
use App\Models\SourceItem;
use App\Models\User;
use App\Models\ValidationBatch;
use App\Models\ValidationBatchItem;
use InvalidArgumentException;

final class ValidationCleanupService
{
    /**
     * @return array<string, mixed>
     */
    public function plan(User $user, string $marker): array
    {
        $batch = $this->batch($user, $marker);
        $counts = $batch->items->groupBy('entity_type')->map->count()->all();

        return [
            'marker' => $batch->marker,
            'will_remove' => $counts,
            'counts' => $counts,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function execute(User $user, string $marker): array
    {
        $batch = $this->batch($user, $marker);
        $removed = [];

        $ids = fn (string $type) => $batch->items->where('entity_type', $type)->pluck('entity_id');

        $proposalIds = $ids('proactive_proposal');
        ProactiveProposalAudit::query()->whereIn('proactive_proposal_id', $proposalIds)->delete();
        $removed['proactive_proposals'] = ProactiveProposal::query()->where('user_id', $user->id)->whereIn('id', $proposalIds)->delete();
        $removed['operational_events'] = OperationalEvent::query()->where('user_id', $user->id)->whereIn('id', $ids('operational_event'))->delete();
        $removed['source_items'] = SourceItem::query()->where('user_id', $user->id)->whereIn('id', $ids('source_item'))->delete();

        $commitmentIds = $ids('commitment');
        CommitmentEvidence::query()->whereIn('commitment_id', $commitmentIds)->delete();
        CommitmentStatusHistory::query()->whereIn('commitment_id', $commitmentIds)->delete();
        $removed['commitments'] = Commitment::query()->where('user_id', $user->id)->whereIn('id', $commitmentIds)->delete();
        $removed['meetings'] = Meeting::query()->where('user_id', $user->id)->whereIn('id', $ids('meeting'))->delete();
        $removed['people'] = Person::query()->where('user_id', $user->id)->whereIn('id', $ids('person'))->delete();
        $removed['organizations'] = Organization::query()->where('user_id', $user->id)->whereIn('id', $ids('organization'))->delete();
        $removed['projects'] = Project::query()->where('user_id', $user->id)->whereIn('id', $ids('project'))->delete();

        ValidationBatchItem::query()->where('validation_batch_id', $batch->id)->delete();
        $batch->delete();

        return $removed;
    }

    private function batch(User $user, string $marker): ValidationBatch
    {
        $batch = ValidationBatch::query()
            ->where('user_id', $user->id)
            ->where('marker', $marker)
            ->with('items')
            ->first();

        if ($batch === null) {
            throw new InvalidArgumentException('Unknown validation batch marker.');
        }

        return $batch;
    }
}
