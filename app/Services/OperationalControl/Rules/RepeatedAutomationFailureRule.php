<?php

namespace App\Services\OperationalControl\Rules;

use App\Enums\AutomationRunOutcome;
use App\Enums\OperationalActionability;
use App\Enums\OperationalEventType;
use App\Enums\OperationalRuleKey;
use App\Enums\OperationalSeverity;
use App\Enums\ProactiveProposalType;
use App\Models\AutomationRun;
use App\Models\User;
use App\Services\OperationalControl\Contracts\OperationalRule;
use App\Services\OperationalControl\OperationalFingerprint;
use App\Services\OperationalControl\OperationalRuleMatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

final class RepeatedAutomationFailureRule implements OperationalRule
{
    public function key(): string
    {
        return OperationalRuleKey::RepeatedAutomationFailure->value;
    }

    public function evaluate(User $user): array
    {
        if (! Schema::hasTable('automation_runs')) {
            return [];
        }

        $hours = max(1, (int) config('operational_control.automation_failure_hours', 6));
        $threshold = max(2, (int) config('operational_control.automation_failure_count', 3));
        $since = CarbonImmutable::now('UTC')->subHours($hours);

        $rows = AutomationRun::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [AutomationRunOutcome::Failed, AutomationRunOutcome::Retryable])
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->get()
            ->groupBy(fn (AutomationRun $run): string => (string) $run->automation_type->value.'|'.$run->automation_id);

        $matches = [];
        foreach ($rows as $group) {
            if ($group->count() < $threshold) {
                continue;
            }

            /** @var AutomationRun $sample */
            $sample = $group->last();
            $type = $sample->automation_type->value;
            $blocksBrief = in_array($type, ['executive_brief', 'brief', 'commitment'], true);

            $matches[] = new OperationalRuleMatch(
                eventType: OperationalEventType::AutomationRepeatedFailure,
                severity: $blocksBrief ? OperationalSeverity::Critical : OperationalSeverity::High,
                fingerprint: OperationalFingerprint::make('automation.repeated_failure', $type, (string) $sample->automation_id),
                rationale: 'Automation '.$type.' failed '.$group->count().' times in '.$hours.'h.',
                proposalType: ProactiveProposalType::OpenSource,
                title: 'Repeated automation failure',
                recommendedAction: 'open_source',
                actionability: OperationalActionability::Review,
                occurredAt: $sample->created_at?->toImmutable() ?? CarbonImmutable::now('UTC'),
                evidence: [
                    'automation_type' => $type,
                    'automation_id' => $sample->automation_id,
                    'failure_count' => $group->count(),
                ],
                payload: [
                    'automation_type' => $type,
                    'failure_count' => $group->count(),
                ],
                sourceType: 'automation_run',
                sourceId: (int) $sample->id,
                confidence: 'high',
                evidencePointer: 'automation:'.$type.':'.$sample->automation_id,
                href: '/lavr?settings=integrations',
            );
        }

        return $matches;
    }
}
