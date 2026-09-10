<?php

namespace App\Services\Commitments;

final class CommitmentToolPrompt
{
    /**
     * @return list<string>
     */
    public static function toolNames(): array
    {
        return [
            'list_commitments',
            'find_commitment',
            'get_commitment',
            'create_manual_commitment',
            'confirm_commitment',
            'mark_commitment_confirmed',
            'cancel_commitment',
            'update_commitment_deadline',
        ];
    }

    /**
     * @return list<string>
     */
    public static function lines(): array
    {
        return [
            'First-class Commitments are stored promises by a Person, not Tasks. list_commitments / find_commitment / get_commitment read the commitments table first.',
            'Use them for “що Сергій обіцяв”, “що прострочено”, “до п’ятниці”, “хто не виконав”, “що схоже на виконане”, “зобов’язання по Chicago”.',
            'commitments_detected in Meeting Intelligence are suggestions until promoted. Do not invent commitments from transcript.',
            'create_manual_commitment / confirm_commitment / mark_commitment_confirmed / cancel_commitment / update_commitment_deadline require explicit Owner intent. Never create a commitment from vague language or from an unconfirmed meeting item.',
            'Do not mix legacy Knowledge list_commitments results with first-class rows. If first-class data exists, ignore derived knowledge commitments.',
        ];
    }
}
