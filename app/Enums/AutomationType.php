<?php

namespace App\Enums;

enum AutomationType: string
{
    case Reminder = 'reminder';
    case Watcher = 'watcher';
    case ScheduledReport = 'scheduled_report';
    case Commitment = 'commitment';
    case Proactive = 'proactive';
    case Brief = 'brief';
    case ExecutiveBrief = 'executive_brief';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
