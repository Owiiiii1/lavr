<?php

namespace App\Services\OperationalControl;

final class OperationalFingerprint
{
    public static function make(string ...$parts): string
    {
        $normalized = array_map(
            static fn (string $part): string => mb_strtolower(trim($part)),
            $parts,
        );

        return hash('sha256', implode('|', $normalized));
    }
}
