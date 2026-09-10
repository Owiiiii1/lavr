<?php

namespace App\Enums;

enum CommitmentEffectiveStatus: string
{
    case Detected = 'detected';
    case Open = 'open';
    case DueSoon = 'due_soon';
    case Overdue = 'overdue';
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

    public static function fromLifecycle(CommitmentLifecycleStatus $lifecycle): self
    {
        return match ($lifecycle) {
            CommitmentLifecycleStatus::Detected => self::Detected,
            CommitmentLifecycleStatus::Open => self::Open,
            CommitmentLifecycleStatus::LikelyDone => self::LikelyDone,
            CommitmentLifecycleStatus::Confirmed => self::Confirmed,
            CommitmentLifecycleStatus::Cancelled => self::Cancelled,
            CommitmentLifecycleStatus::Discarded => self::Discarded,
        };
    }

    public function lifecycle(): CommitmentLifecycleStatus
    {
        return match ($this) {
            self::Detected => CommitmentLifecycleStatus::Detected,
            self::Open, self::DueSoon, self::Overdue => CommitmentLifecycleStatus::Open,
            self::LikelyDone => CommitmentLifecycleStatus::LikelyDone,
            self::Confirmed => CommitmentLifecycleStatus::Confirmed,
            self::Cancelled => CommitmentLifecycleStatus::Cancelled,
            self::Discarded => CommitmentLifecycleStatus::Discarded,
        };
    }

    public static function tryFromLoose(mixed $value): ?self
    {
        $raw = is_string($value) ? mb_strtolower(trim($value)) : '';

        return match ($raw) {
            'detected' => self::Detected,
            'open', 'active' => self::Open,
            'due_soon', 'due-soon' => self::DueSoon,
            'overdue' => self::Overdue,
            'likely_done' => self::LikelyDone,
            'confirmed', 'done', 'completed', 'fulfilled' => self::Confirmed,
            'cancelled', 'canceled' => self::Cancelled,
            'discarded' => self::Discarded,
            default => self::tryFrom($raw),
        };
    }
}
