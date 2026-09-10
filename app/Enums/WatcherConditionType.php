<?php

namespace App\Enums;

enum WatcherConditionType: string
{
    case EventExists = 'event_exists';
    case EntityEventType = 'entity_event_type';
    case StatusEquals = 'status_equals';
    case StatusChanged = 'status_changed';
    case DeadlineWithin = 'deadline_within';
    case OverdueBy = 'overdue_by';
    case NewItem = 'new_item';
    case SenderMatches = 'sender_matches';
    case SubjectContains = 'subject_contains';
    case ThreadReceivedReply = 'thread_received_reply';
    case CalendarChanged = 'calendar_changed';
    case GithubNewCommit = 'github_new_commit';
    case GithubPrStateChanged = 'github_pr_state_changed';
    case GithubWorkflowFailed = 'github_workflow_failed';

    public static function tryFromLoose(mixed $value): ?self
    {
        $raw = is_string($value) ? mb_strtolower(trim($value)) : '';
        $raw = str_replace([' ', '-'], '_', $raw);

        return match ($raw) {
            'event', 'exists' => self::EventExists,
            'entity_event', 'knowledge_event' => self::EntityEventType,
            'status', 'still_open', 'remains_open', 'still_open_tomorrow', 'open_tomorrow', 'if_open' => self::StatusEquals,
            'changed' => self::StatusChanged,
            'deadline', 'due_soon' => self::DeadlineWithin,
            'overdue', 'past_due' => self::OverdueBy,
            'new', 'created' => self::NewItem,
            'sender', 'from' => self::SenderMatches,
            'subject' => self::SubjectContains,
            'thread', 'reply' => self::ThreadReceivedReply,
            'calendar', 'rescheduled', 'time_changed' => self::CalendarChanged,
            'commit', 'new_commit' => self::GithubNewCommit,
            'pr', 'pull_request' => self::GithubPrStateChanged,
            'workflow', 'ci_failed', 'actions_failed' => self::GithubWorkflowFailed,
            default => self::tryFrom($raw),
        };
    }
}
