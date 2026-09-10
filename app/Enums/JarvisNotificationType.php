<?php

namespace App\Enums;

enum JarvisNotificationType: string
{
    case ReminderDue = 'reminder_due';
    case TaskDue = 'task_due';
    case TaskOverdue = 'task_overdue';
    case BriefReady = 'brief_ready';
    case ProactiveSuggestion = 'proactive_suggestion';
    case WatcherTriggered = 'watcher_triggered';
    case ScheduledReportReady = 'scheduled_report_ready';
    case MeetingAnalyzed = 'meeting_analyzed';
}
