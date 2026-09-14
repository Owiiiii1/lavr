<?php

namespace App\Services\OperationalControl;

use App\Enums\ExternalActionLevel;
use App\Enums\OperationalEventStatus;
use App\Enums\OperationalEventType;
use App\Enums\OperationalSeverity;
use App\Enums\ProactiveAuditAction;
use App\Enums\ProactiveProposalStatus;
use App\Enums\ProactiveProposalType;
use App\Models\OperationalEvent;
use App\Models\ProactiveProposal;
use App\Models\ProactiveProposalAudit;
use App\Models\User;
use App\Services\Automation\ExternalActionPolicy;
use Carbon\CarbonImmutable;

final class ProactiveProposalService
{
    public function __construct(
        private readonly ExternalActionPolicy $policy,
    ) {}

    public function propose(User $user, OperationalEvent $event, OperationalRuleMatch $match, OperationalAssessment $assessment): ?ProactiveProposal
    {
        if (! $assessment->actionable) {
            return null;
        }

        if (! $event->status instanceof OperationalEventStatus || ! $event->status->isOpen()) {
            return null;
        }

        $fingerprint = OperationalFingerprint::make(
            $match->proposalType->value,
            (string) $event->fingerprint,
        );

        $existing = ProactiveProposal::query()
            ->where('user_id', $user->id)
            ->where('fingerprint', $fingerprint)
            ->first();

        if ($existing instanceof ProactiveProposal) {
            if ($existing->status === ProactiveProposalStatus::Pending) {
                $existing->forceFill([
                    'severity' => $assessment->severity,
                    'rationale' => $match->rationale,
                    'title' => $match->title,
                ])->save();
            }

            return $existing;
        }

        $level = $this->policy->levelFor($user, $match->proposalType->value);
        $proposal = ProactiveProposal::query()->create([
            'user_id' => $user->id,
            'operational_event_id' => $event->id,
            'person_id' => $match->personId,
            'project_id' => $match->projectId,
            'commitment_id' => $match->commitmentId,
            'meeting_id' => $match->meetingId,
            'proposal_type' => $match->proposalType,
            'title' => $match->title,
            'rationale' => $match->rationale,
            'action_payload_json' => [
                'action' => $match->recommendedAction,
                'href' => $match->href,
                'draft_status' => $match->proposalType === ProactiveProposalType::DraftEmail
                    || $match->proposalType === ProactiveProposalType::DraftTelegramMessage
                    ? 'draft'
                    : null,
            ],
            'status' => ProactiveProposalStatus::Pending,
            'requires_confirmation' => $level !== ExternalActionLevel::Execute,
            'policy_level' => $level,
            'fingerprint' => $fingerprint,
            'severity' => $assessment->severity,
            'expires_at' => CarbonImmutable::now('UTC')->addDays((int) config('operational_control.proposal_retention_days', 30)),
        ]);

        $this->audit($proposal, ProactiveAuditAction::Created, 'created');

        return $proposal;
    }

    /**
     * @return list<ProactiveProposal>
     */
    public function pendingForUser(User $user, ?int $limit = 20): array
    {
        return ProactiveProposal::query()
            ->with(['person:id,display_name', 'project:id,name', 'commitment:id,title', 'event'])
            ->where('user_id', $user->id)
            ->where('status', ProactiveProposalStatus::Pending)
            ->where(function ($query): void {
                $query->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
            })
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END")
            ->orderByDesc('id')
            ->limit($limit ?? 20)
            ->get()
            ->all();
    }

    /**
     * @return list<ProactiveProposal>
     */
    public function pendingForPerson(User $user, int $personId): array
    {
        return $this->pendingWhere($user, 'person_id', $personId);
    }

    /**
     * @return list<ProactiveProposal>
     */
    public function pendingForProject(User $user, int $projectId): array
    {
        return $this->pendingWhere($user, 'project_id', $projectId);
    }

    /**
     * @return list<ProactiveProposal>
     */
    public function pendingForCommitment(User $user, int $commitmentId): array
    {
        return $this->pendingWhere($user, 'commitment_id', $commitmentId);
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(ProactiveProposal $proposal): array
    {
        $payload = is_array($proposal->action_payload_json) ? $proposal->action_payload_json : [];

        return [
            'id' => $proposal->id,
            'proposal_type' => $proposal->proposal_type instanceof ProactiveProposalType ? $proposal->proposal_type->value : (string) $proposal->proposal_type,
            'title' => $proposal->title,
            'rationale' => $proposal->rationale,
            'status' => $proposal->status instanceof ProactiveProposalStatus ? $proposal->status->value : (string) $proposal->status,
            'severity' => $proposal->severity instanceof OperationalSeverity ? $proposal->severity->value : (string) $proposal->severity,
            'requires_confirmation' => (bool) $proposal->requires_confirmation,
            'policy_level' => $proposal->policy_level instanceof ExternalActionLevel ? $proposal->policy_level->value : (string) $proposal->policy_level,
            'href' => '/lavr/proactive/'.$proposal->id,
            'source_href' => $payload['href'] ?? null,
            'draft_status' => $payload['draft_status'] ?? null,
            'draft_body' => $payload['draft_body'] ?? null,
            'person' => $proposal->person ? ['id' => $proposal->person->id, 'display_name' => $proposal->person->display_name] : null,
            'project' => $proposal->project ? ['id' => $proposal->project->id, 'name' => $proposal->project->name] : null,
            'commitment' => $proposal->commitment ? ['id' => $proposal->commitment->id, 'title' => $proposal->commitment->title] : null,
            'event_type' => $proposal->event?->event_type instanceof OperationalEventType ? $proposal->event->event_type->value : null,
            'snoozed_until' => optional($proposal->snoozed_until)?->toIso8601String(),
            'acted_at' => optional($proposal->acted_at)?->toIso8601String(),
        ];
    }

    public function audit(
        ProactiveProposal $proposal,
        ProactiveAuditAction $action,
        ?string $outcome = null,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $externalReference = null,
        array $metadata = [],
    ): void {
        unset($metadata['body'], $metadata['message'], $metadata['draft_body']);

        ProactiveProposalAudit::query()->create([
            'proactive_proposal_id' => $proposal->id,
            'user_id' => $proposal->user_id,
            'action' => $action,
            'outcome' => $outcome,
            'target_canonical_type' => $targetType,
            'target_canonical_id' => $targetId,
            'external_reference' => $externalReference,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /**
     * @return list<ProactiveProposal>
     */
    private function pendingWhere(User $user, string $column, int $id): array
    {
        return ProactiveProposal::query()
            ->with(['person:id,display_name', 'project:id,name', 'commitment:id,title', 'event'])
            ->where('user_id', $user->id)
            ->where($column, $id)
            ->where('status', ProactiveProposalStatus::Pending)
            ->where(function ($query): void {
                $query->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
            })
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->all();
    }
}
