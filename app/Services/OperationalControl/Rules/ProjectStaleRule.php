<?php

namespace App\Services\OperationalControl\Rules;

use App\Enums\CommitmentEffectiveStatus;
use App\Enums\CommitmentLifecycleStatus;
use App\Enums\OperationalActionability;
use App\Enums\OperationalEventType;
use App\Enums\OperationalRuleKey;
use App\Enums\OperationalSeverity;
use App\Enums\ProactiveProposalType;
use App\Enums\ProjectStatus;
use App\Models\Commitment;
use App\Models\Project;
use App\Models\SourceItem;
use App\Models\User;
use App\Services\OperationalControl\Contracts\OperationalRule;
use App\Services\OperationalControl\OperationalFingerprint;
use App\Services\OperationalControl\OperationalRuleMatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

final class ProjectStaleRule implements OperationalRule
{
    public function key(): string
    {
        return OperationalRuleKey::ProjectStale->value;
    }

    public function evaluate(User $user): array
    {
        if (! Schema::hasTable('projects')) {
            return [];
        }

        $days = max(1, (int) config('operational_control.stale_project_days', 7));
        $since = CarbonImmutable::now('UTC')->subDays($days);

        $projects = Project::query()
            ->where('user_id', $user->id)
            ->where('status', ProjectStatus::Active)
            ->orderBy('id')
            ->limit(40)
            ->get();

        $matches = [];
        foreach ($projects as $project) {
            $active = Commitment::query()
                ->where('user_id', $user->id)
                ->where('project_id', $project->id)
                ->whereNull('merged_into_id')
                ->whereIn('lifecycle_status', [
                    CommitmentLifecycleStatus::Open,
                    CommitmentLifecycleStatus::LikelyDone,
                    CommitmentLifecycleStatus::Detected,
                ])
                ->get();

            if ($active->isEmpty()) {
                continue;
            }

            $hasBlocker = $active->contains(
                fn (Commitment $commitment): bool => $commitment->status === CommitmentEffectiveStatus::Overdue
            );

            if (! $hasBlocker) {
                continue;
            }

            $recentActivity = Schema::hasTable('source_items')
                && SourceItem::query()
                    ->where('user_id', $user->id)
                    ->where('project_id', $project->id)
                    ->where('occurred_at', '>=', $since)
                    ->exists();

            if ($recentActivity) {
                continue;
            }

            $matches[] = new OperationalRuleMatch(
                eventType: OperationalEventType::ProjectStale,
                severity: OperationalSeverity::Normal,
                fingerprint: OperationalFingerprint::make('project.stale', (string) $project->id),
                rationale: 'Project "'.$project->name.'" has active obligations and no source activity for '.$days.' days.',
                proposalType: ProactiveProposalType::OpenSource,
                title: 'Project looks stale',
                recommendedAction: 'open_source',
                actionability: OperationalActionability::Review,
                occurredAt: $since,
                evidence: ['project_id' => $project->id, 'quiet_days' => $days],
                payload: ['project' => $project->name],
                sourceType: 'project',
                sourceId: (int) $project->id,
                projectId: (int) $project->id,
                confidence: 'medium',
                evidencePointer: 'project:'.$project->id,
                href: '/lavr/projects/'.$project->id,
            );
        }

        return $matches;
    }
}
