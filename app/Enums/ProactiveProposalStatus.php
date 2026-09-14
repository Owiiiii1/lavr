<?php

namespace App\Enums;

enum ProactiveProposalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Executed = 'executed';
    case Dismissed = 'dismissed';
    case Expired = 'expired';
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
