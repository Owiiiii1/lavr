<?php

namespace App\Enums;

enum CommitmentLifecycleStatus: string
{
    case Detected = 'detected';
    case Open = 'open';
    case LikelyDone = 'likely_done';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Discarded = 'discarded';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Confirmed, self::Cancelled, self::Discarded], true);
    }

    public function isActive(): bool
    {
        return $this === self::Open;
    }

    public static function tryFromLoose(mixed $value): ?self
    {
        $raw = is_string($value) ? mb_strtolower(trim($value)) : '';

        return match ($raw) {
            'detected', 'pending_review' => self::Detected,
            'open', 'active' => self::Open,
            'likely_done', 'probably_done' => self::LikelyDone,
            'confirmed', 'done', 'completed', 'fulfilled' => self::Confirmed,
            'cancelled', 'canceled' => self::Cancelled,
            'discarded', 'not_a_commitment', 'false_positive' => self::Discarded,
            default => self::tryFrom($raw),
        };
    }
}
