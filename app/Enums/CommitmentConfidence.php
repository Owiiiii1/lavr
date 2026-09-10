<?php

namespace App\Enums;

enum CommitmentConfidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $confidence): string => $confidence->value, self::cases());
    }

    public static function fromLoose(mixed $value): self
    {
        $raw = is_string($value) ? mb_strtolower(trim($value)) : 'medium';

        return self::tryFrom($raw) ?? self::Medium;
    }
}
