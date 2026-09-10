<?php

namespace App\Services\Commitments;

use App\Services\Projects\ProjectNameNormalizer;

final class CommitmentFingerprint
{
    public static function make(
        int $userId,
        string $sourceCluster,
        ?int $personId,
        ?string $personName,
        string $action,
        ?string $expectedResult,
        ?string $deadlineRaw,
    ): string {
        $parts = [
            'user:'.$userId,
            'cluster:'.ProjectNameNormalizer::normalize($sourceCluster),
            'person:'.($personId !== null && $personId > 0
                ? 'id:'.$personId
                : 'name:'.ProjectNameNormalizer::normalize((string) $personName)),
            'action:'.ProjectNameNormalizer::normalize($action),
            'result:'.ProjectNameNormalizer::normalize((string) $expectedResult),
            'deadline:'.ProjectNameNormalizer::normalize((string) $deadlineRaw),
        ];

        return hash('sha256', implode('|', $parts));
    }

    public static function uniqueManual(): string
    {
        return hash('sha256', 'manual:'.bin2hex(random_bytes(16)).':'.microtime(true));
    }
}
