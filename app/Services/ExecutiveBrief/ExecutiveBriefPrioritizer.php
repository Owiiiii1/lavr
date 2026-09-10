<?php

namespace App\Services\ExecutiveBrief;

use App\Enums\ExecutiveBriefPriority;

final class ExecutiveBriefPrioritizer
{
    /**
     * @param  array{
     *     overdue?: bool,
     *     due_today?: bool,
     *     hours_until?: float|int|null,
     *     risk?: bool,
     *     blocked?: bool,
     *     owner_action?: bool,
     *     repeated?: bool,
     *     fyi?: bool
     * }  $factors
     * @return array{priority: ExecutiveBriefPriority, score: int}
     */
    public function rank(array $factors): array
    {
        $score = 20;
        $priority = ExecutiveBriefPriority::Low;

        if (($factors['fyi'] ?? false) === true) {
            return ['priority' => ExecutiveBriefPriority::Low, 'score' => 12];
        }

        if (($factors['blocked'] ?? false) === true) {
            return ['priority' => ExecutiveBriefPriority::Critical, 'score' => 88];
        }

        if (($factors['overdue'] ?? false) === true) {
            $score = 92;
            if (($factors['repeated'] ?? false) === true) {
                $score = 95;
            }

            return ['priority' => ExecutiveBriefPriority::Critical, 'score' => $score];
        }

        if (($factors['risk'] ?? false) === true) {
            return ['priority' => ExecutiveBriefPriority::High, 'score' => 80];
        }

        if (($factors['due_today'] ?? false) === true) {
            return ['priority' => ExecutiveBriefPriority::High, 'score' => 78];
        }

        if (($factors['owner_action'] ?? false) === true) {
            return ['priority' => ExecutiveBriefPriority::High, 'score' => 70];
        }

        $hours = $factors['hours_until'] ?? null;
        if (is_numeric($hours) && (float) $hours >= 0 && (float) $hours <= 3) {
            return ['priority' => ExecutiveBriefPriority::High, 'score' => 74];
        }

        if (is_numeric($hours) && (float) $hours > 3 && (float) $hours <= 12) {
            return ['priority' => ExecutiveBriefPriority::Normal, 'score' => 52];
        }

        return ['priority' => $priority, 'score' => $score];
    }
}
