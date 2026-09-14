<?php

namespace App\Enums;

enum OperationalRuleKey: string
{
    case OverdueCommitment = 'overdue_commitment';
    case LikelyDone = 'likely_done';
    case MissingFollowUp = 'missing_follow_up';
    case ImportantUnansweredEmail = 'important_unanswered_email';
    case BlockedIntegration = 'blocked_integration';
    case RepeatedAutomationFailure = 'repeated_automation_failure';
    case ProjectStale = 'project_stale';
    case UnresolvedMeetingAction = 'unresolved_meeting_action';
    case DetectedCommitmentReview = 'detected_commitment';
    case TelegramBlocker = 'telegram_blocker';
    case DecisionLikeUnresolved = 'decision_like_unresolved';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $key): string => $key->value, self::cases());
    }
}
