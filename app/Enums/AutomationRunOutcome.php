<?php

namespace App\Enums;

enum AutomationRunOutcome: string
{
    case Processing = 'processing';
    case Success = 'success';
    case Skipped = 'skipped';
    case NoChange = 'no_change';
    case Partial = 'partial';
    case Failed = 'failed';
    case Retryable = 'retryable';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Success, self::Partial, self::Failed], true);
    }
}
