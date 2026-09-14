<?php

namespace App\Enums;

enum OperationalEventType: string
{
    case CommitmentDetected = 'commitment.detected';
    case CommitmentDueSoon = 'commitment.due_soon';
    case CommitmentOverdue = 'commitment.overdue';
    case CommitmentLikelyDone = 'commitment.likely_done';
    case CommitmentConfirmed = 'commitment.confirmed';
    case MeetingAnalysisCompleted = 'meeting.analysis_completed';
    case MeetingRiskDetected = 'meeting.risk_detected';
    case MeetingUnresolvedActions = 'meeting.unresolved_actions';
    case EmailImportantReceived = 'email.important_received';
    case EmailReplyExpected = 'email.reply_expected';
    case EmailDeliveryEvidence = 'email.delivery_evidence';
    case TelegramCommitmentDetected = 'telegram.commitment_detected';
    case TelegramBlockerDetected = 'telegram.blocker_detected';
    case TelegramDeliveryEvidence = 'telegram.delivery_evidence';
    case ProjectBlocked = 'project.blocked';
    case ProjectStale = 'project.stale';
    case IntegrationBlocked = 'integration.blocked';
    case AutomationRepeatedFailure = 'automation.repeated_failure';
    case DecisionLikeUnresolved = 'decision_like.unresolved';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
