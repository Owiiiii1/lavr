<?php

namespace App\Enums;

enum CommitmentSourceType: string
{
    case Meeting = 'meeting';
    case Email = 'email';
    case Telegram = 'telegram';
    case Manual = 'manual';
    case KnowledgeLegacy = 'knowledge_legacy';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
