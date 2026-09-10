<?php

namespace App\Enums;

enum AutomationIntentKind: string
{
    case Reminder = 'reminder';
    case Watcher = 'watcher';
    case ScheduledReport = 'scheduled_report';
    case Clarify = 'clarify';
}
