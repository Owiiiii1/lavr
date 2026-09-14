<?php

namespace App\Enums;

enum ProactiveProposalType: string
{
    case RemindPerson = 'remind_person';
    case DraftEmail = 'draft_email';
    case DraftTelegramMessage = 'draft_telegram_message';
    case ConfirmCommitment = 'confirm_commitment';
    case UpdateDeadline = 'update_deadline';
    case CreateReminder = 'create_reminder';
    case CreateWatcher = 'create_watcher';
    case ScheduleFollowup = 'schedule_followup';
    case OpenSource = 'open_source';
    case ReviewDetectedCommitment = 'review_detected_commitment';
    case ReconnectIntegration = 'reconnect_integration';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }

    public function isExternalWrite(): bool
    {
        return in_array($this, [
            self::RemindPerson,
            self::DraftEmail,
            self::DraftTelegramMessage,
        ], true);
    }
}
