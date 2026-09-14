<?php

namespace App\Services\OperationalControl;

use App\Enums\CommitmentLifecycleStatus;
use App\Enums\IntegrationAccountStatus;
use App\Enums\OperationalEventStatus;
use App\Enums\ProactiveAuditAction;
use App\Enums\ProactiveProposalStatus;
use App\Enums\ProactiveProposalType;
use App\Models\Commitment;
use App\Models\IntegrationAccount;
use App\Models\ProactiveProposal;
use App\Models\SourceItem;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

final class ProactiveProposalReconciler
{
    public function __construct(
        private readonly ProactiveProposalService $proposals,
    ) {}

    public function reconcileUser(User $user): int
    {
        $proposals = ProactiveProposal::query()
            ->with(['event', 'commitment'])
            ->where('user_id', $user->id)
            ->where('status', ProactiveProposalStatus::Pending)
            ->orderBy('id')
            ->limit(200)
            ->get();

        $count = 0;
        foreach ($proposals as $proposal) {
            if ($this->shouldSupersede($proposal)) {
                $this->supersede($proposal);
                $count++;
            }
        }

        return $count;
    }

    public function shouldSupersede(ProactiveProposal $proposal): bool
    {
        if ($proposal->expires_at !== null && $proposal->expires_at->isPast()) {
            return true;
        }

        $type = $proposal->proposal_type instanceof ProactiveProposalType
            ? $proposal->proposal_type
            : ProactiveProposalType::tryFrom((string) $proposal->proposal_type);

        return match ($type) {
            ProactiveProposalType::RemindPerson, ProactiveProposalType::ScheduleFollowup => $this->commitmentNoLongerOverdue($proposal),
            ProactiveProposalType::ConfirmCommitment => $this->commitmentAlreadyClosed($proposal),
            ProactiveProposalType::ReviewDetectedCommitment => $this->commitmentNoLongerDetected($proposal),
            ProactiveProposalType::ReconnectIntegration => $this->integrationHealthy($proposal),
            ProactiveProposalType::DraftEmail => $this->emailAlreadyReplied($proposal),
            default => false,
        };
    }

    public function supersede(ProactiveProposal $proposal, string $reason = 'state_changed'): void
    {
        $proposal->forceFill([
            'status' => ProactiveProposalStatus::Expired,
            'acted_at' => now(),
            'dismiss_reason' => $reason,
        ])->save();

        if ($proposal->event !== null && $proposal->event->status instanceof OperationalEventStatus && $proposal->event->status->isOpen()) {
            $proposal->event->forceFill(['status' => OperationalEventStatus::Superseded])->save();
        }

        $this->proposals->audit($proposal, ProactiveAuditAction::AutoResolved, 'superseded');
    }

    public function revalidateForExecute(ProactiveProposal $proposal): bool
    {
        return ! $this->shouldSupersede($proposal);
    }

    private function commitmentNoLongerOverdue(ProactiveProposal $proposal): bool
    {
        $commitment = $this->commitment($proposal);
        if ($commitment === null) {
            return true;
        }

        return $commitment->lifecycle_status?->isTerminal() === true
            || $commitment->lifecycle_status === CommitmentLifecycleStatus::LikelyDone;
    }

    private function commitmentAlreadyClosed(ProactiveProposal $proposal): bool
    {
        $commitment = $this->commitment($proposal);
        if ($commitment === null) {
            return true;
        }

        return $commitment->lifecycle_status?->isTerminal() === true;
    }

    private function commitmentNoLongerDetected(ProactiveProposal $proposal): bool
    {
        $commitment = $this->commitment($proposal);
        if ($commitment === null) {
            return true;
        }

        return $commitment->lifecycle_status !== CommitmentLifecycleStatus::Detected;
    }

    private function integrationHealthy(ProactiveProposal $proposal): bool
    {
        $event = $proposal->event;
        if ($event === null || $event->source_type !== 'integration_account' || $event->source_id === null) {
            return false;
        }

        $account = IntegrationAccount::query()->find($event->source_id);
        if ($account === null) {
            return true;
        }

        return ! in_array($account->status, [
            IntegrationAccountStatus::Error,
            IntegrationAccountStatus::Revoked,
            IntegrationAccountStatus::Disconnected,
        ], true) && $account->last_error_code !== 'blocked_auth';
    }

    private function emailAlreadyReplied(ProactiveProposal $proposal): bool
    {
        if (! Schema::hasTable('source_items') || $proposal->event?->source_id === null) {
            return false;
        }

        $item = SourceItem::query()->find($proposal->event->source_id);
        if ($item === null) {
            return true;
        }

        $metadata = is_array($item->metadata) ? $item->metadata : [];

        return ($metadata['thread_has_reply'] ?? false) === true;
    }

    private function commitment(ProactiveProposal $proposal): ?Commitment
    {
        if ($proposal->relationLoaded('commitment')) {
            return $proposal->commitment;
        }

        if ($proposal->commitment_id === null) {
            return null;
        }

        return Commitment::query()->find($proposal->commitment_id);
    }
}
