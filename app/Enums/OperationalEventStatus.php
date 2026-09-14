<?php

namespace App\Enums;

enum OperationalEventStatus: string
{
    case Observed = 'observed';
    case Assessed = 'assessed';
    case Actionable = 'actionable';
    case Dismissed = 'dismissed';
    case Resolved = 'resolved';
    case Superseded = 'superseded';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Observed, self::Assessed, self::Actionable], true);
    }
}
