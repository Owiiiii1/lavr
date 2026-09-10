<?php

namespace App\Services\Commitments;

use App\Enums\CommitmentEffectiveStatus;
use App\Enums\CommitmentLifecycleStatus;
use App\Models\Commitment;
use App\Models\CommitmentStatusHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final class CommitmentStatusService
{
    public function effective(
        Commitment $commitment,
        ?CarbonImmutable $now = null,
        ?int $dueSoonHours = null,
    ): CommitmentEffectiveStatus {
        $lifecycle = $commitment->lifecycle_status instanceof CommitmentLifecycleStatus
            ? $commitment->lifecycle_status
            : CommitmentLifecycleStatus::tryFromLoose((string) $commitment->lifecycle_status) ?? CommitmentLifecycleStatus::Detected;

        if (! $lifecycle->isActive()) {
            return CommitmentEffectiveStatus::fromLifecycle($lifecycle);
        }

        $deadline = $commitment->deadline_at?->toImmutable();

        if ($deadline === null) {
            return CommitmentEffectiveStatus::Open;
        }

        $now ??= CarbonImmutable::now($commitment->deadline_at->getTimezone()->getName());
        $hours = $dueSoonHours ?? CommitmentConfig::dueSoonHours();

        if ($deadline->lessThan($now)) {
            return CommitmentEffectiveStatus::Overdue;
        }

        if ($deadline->lessThanOrEqualTo($now->addHours($hours))) {
            return CommitmentEffectiveStatus::DueSoon;
        }

        return CommitmentEffectiveStatus::Open;
    }

    public function persist(Commitment $commitment, ?User $actor = null, string $reason = 'recompute'): CommitmentEffectiveStatus
    {
        $next = $this->effective($commitment);
        $current = $commitment->status instanceof CommitmentEffectiveStatus
            ? $commitment->status
            : CommitmentEffectiveStatus::tryFromLoose((string) $commitment->status);

        if ($current === $next) {
            return $next;
        }

        $from = $current?->value;
        $commitment->forceFill(['status' => $next])->save();
        $this->recordHistory($commitment, $from, $next->value, $reason, $actor);

        Log::info('commitment status changed', [
            'commitment_id' => $commitment->id,
            'person_id' => $commitment->person_id,
            'from_status' => $from,
            'to_status' => $next->value,
            'reason' => $reason,
            'source_type' => $commitment->source_type instanceof \BackedEnum ? $commitment->source_type->value : (string) $commitment->source_type,
            'source_id' => $commitment->source_id,
        ]);

        return $next;
    }

    public function setLifecycle(
        Commitment $commitment,
        CommitmentLifecycleStatus $lifecycle,
        ?User $actor = null,
        string $reason = 'lifecycle',
    ): Commitment {
        $commitment->lifecycle_status = $lifecycle;

        if ($lifecycle === CommitmentLifecycleStatus::Open && $commitment->confirmed_at === null) {
            $commitment->confirmed_at = now();
        }

        if ($lifecycle === CommitmentLifecycleStatus::Confirmed) {
            $commitment->completed_at = $commitment->completed_at ?? now();
        }

        if ($lifecycle === CommitmentLifecycleStatus::Cancelled || $lifecycle === CommitmentLifecycleStatus::Discarded) {
            $commitment->cancelled_at = $commitment->cancelled_at ?? now();
        }

        $commitment->save();
        $this->persist($commitment, $actor, $reason);

        return $commitment->fresh() ?? $commitment;
    }

    public function recordHistory(
        Commitment $commitment,
        ?string $from,
        string $to,
        string $reason,
        ?User $actor = null,
        array $metadata = [],
    ): void {
        CommitmentStatusHistory::query()->create([
            'commitment_id' => $commitment->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'changed_by' => $actor?->id,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);
    }
}
