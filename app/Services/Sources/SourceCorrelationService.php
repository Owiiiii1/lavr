<?php

namespace App\Services\Sources;

use App\Enums\CommitmentLifecycleStatus;
use App\Models\Commitment;
use App\Models\User;
use App\Services\Projects\ProjectNameNormalizer;
use Carbon\CarbonImmutable;

final class SourceCorrelationService
{
    /**
     * @param  array{
     *     person_id?: ?int,
     *     project_id?: ?int,
     *     text?: string,
     *     occurred_at?: ?CarbonImmutable
     * }  $signals
     */
    public function findCommitment(User $user, array $signals): ?Commitment
    {
        $personId = isset($signals['person_id']) ? (int) $signals['person_id'] : 0;
        if ($personId < 1) {
            return null;
        }

        $query = Commitment::query()
            ->where('user_id', $user->id)
            ->where('person_id', $personId)
            ->whereNull('merged_into_id')
            ->whereIn('lifecycle_status', [
                CommitmentLifecycleStatus::Detected->value,
                CommitmentLifecycleStatus::Open->value,
                CommitmentLifecycleStatus::LikelyDone->value,
            ]);

        $projectId = isset($signals['project_id']) ? (int) $signals['project_id'] : 0;
        if ($projectId > 0) {
            $query->where('project_id', $projectId);
        }

        $candidates = $query->orderByDesc('id')->limit(8)->get();
        if ($candidates->isEmpty()) {
            return null;
        }

        $text = ProjectNameNormalizer::normalize((string) ($signals['text'] ?? ''));
        if ($text === '') {
            return $candidates->count() === 1 ? $candidates->first() : null;
        }

        $scored = $candidates->filter(function (Commitment $commitment) use ($text): bool {
            $haystack = ProjectNameNormalizer::normalize(
                implode(' ', array_filter([
                    $commitment->title,
                    $commitment->description,
                    $commitment->expected_result,
                ])),
            );

            return $haystack !== '' && (str_contains($text, $haystack) || str_contains($haystack, $text) || $this->sharesToken($haystack, $text));
        });

        if ($scored->count() === 1) {
            return $scored->first();
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function sharesToken(string $left, string $right): bool
    {
        $tokens = array_values(array_filter(explode(' ', $left), fn (string $token): bool => mb_strlen($token) >= 4));
        foreach ($tokens as $token) {
            if (str_contains($right, $token)) {
                return true;
            }
        }

        return false;
    }
}
