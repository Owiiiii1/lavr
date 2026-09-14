<?php

namespace App\Enums;

enum LeadershipFindingCategory: string
{
    case Clarity = 'clarity';
    case Ownership = 'ownership';
    case Deadlines = 'deadlines';
    case FollowUp = 'follow_up';
    case DecisionFollowthrough = 'decision_followthrough';
    case CommitmentReliability = 'commitment_reliability';
    case MeetingEffectiveness = 'meeting_effectiveness';
    case Bottlenecks = 'bottlenecks';
    case WorkloadConcentration = 'workload_concentration';
    case OwnerDependency = 'owner_dependency';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $category): string => $category->value, self::cases());
    }
}
