<?php

namespace App\Services\LeadershipReview;

use App\Enums\OwnerLocale;
use App\Models\User;
use App\Services\Automation\ReportOutputValidator;
use App\Services\Productivity\SynthesizesProductivityBrief;
use Illuminate\Support\Facades\Log;

final class LeadershipReviewComposer
{
    public function __construct(
        private readonly LeadershipReviewWordingGuard $guard = new LeadershipReviewWordingGuard,
        private readonly ReportOutputValidator $output = new ReportOutputValidator,
        private readonly ?SynthesizesProductivityBrief $synthesizer = null,
    ) {}

    /**
     * @param  array<string, mixed>  $pack
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $knownNames
     * @return array{summary: string, pack: array<string, mixed>, generated_by: string, ai_used: bool}
     */
    public function compose(User $user, array $pack, array $metrics, array $snapshot, OwnerLocale $locale, array $knownNames): array
    {
        $summary = $this->deterministicSummary($pack, $metrics, $locale);
        $generatedBy = 'deterministic';
        $aiUsed = false;

        if ($this->synthesizer !== null) {
            $phrased = $this->synthesizer->synthesize($user, 'leadership_review', $summary, [
                'findings' => $pack['attention'] ?? [],
                'strengths' => $pack['strengths'] ?? [],
            ]);
            $candidate = is_string($phrased) ? trim($phrased) : '';
            $reason = $this->rejectAi($candidate, $pack, $metrics, $locale, $knownNames);
            if ($reason === null) {
                $summary = $candidate;
                $generatedBy = 'ai_assisted';
                $aiUsed = true;
            } else {
                Log::info('leadership review phrasing skipped', ['reason' => $reason]);
            }
        }

        $pack['findings'] = $this->sanitizeFindings(is_array($pack['findings'] ?? null) ? $pack['findings'] : []);
        $pack['attention'] = $this->sanitizeFindings(is_array($pack['attention'] ?? null) ? $pack['attention'] : []);
        $pack['strengths'] = $this->sanitizeFindings(is_array($pack['strengths'] ?? null) ? $pack['strengths'] : []);

        return [
            'summary' => $summary,
            'pack' => $pack,
            'generated_by' => $generatedBy,
            'ai_used' => $aiUsed,
        ];
    }

    /**
     * @param  array<string, mixed>  $pack
     * @param  array<string, mixed>  $metrics
     */
    public function deterministicSummary(array $pack, array $metrics, OwnerLocale $locale): string
    {
        $attention = is_array($pack['attention'] ?? null) ? $pack['attention'] : [];
        $strengths = is_array($pack['strengths'] ?? null) ? $pack['strengths'] : [];
        if ($attention === [] && $strengths === []) {
            return LeadershipReviewCopy::emptySummary($locale, $metrics);
        }

        $parts = [];
        foreach (array_slice($attention, 0, 2) as $row) {
            $parts[] = trim((string) ($row['observation'] ?? $row['title'] ?? ''));
        }
        foreach (array_slice($strengths, 0, 1) as $row) {
            $parts[] = trim((string) ($row['observation'] ?? $row['title'] ?? ''));
        }
        $parts = array_values(array_filter($parts));

        return $parts !== [] ? implode(' ', $parts) : LeadershipReviewCopy::emptySummary($locale, $metrics);
    }

    /**
     * @param  array<string, mixed>  $pack
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $knownNames
     */
    public function rejectAi(string $candidate, array $pack, array $metrics, OwnerLocale $locale, array $knownNames): ?string
    {
        if ($candidate === '') {
            return 'empty';
        }
        $output = $this->output->rejectReason($candidate);
        if ($output !== null) {
            return $output;
        }
        $guard = $this->guard->rejectReason($candidate);
        if ($guard !== null) {
            return $guard;
        }
        if ($this->guard->localeMismatch($candidate, $locale)) {
            return 'locale';
        }
        if ($this->guard->mentionsUnknownPerson($candidate, $knownNames)) {
            return 'unknown_person';
        }
        if ($this->inventedNumber($candidate, $metrics, $pack)) {
            return 'invented_number';
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sanitizeFindings(array $rows): array
    {
        $clean = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (['title', 'observation', 'recommendation'] as $field) {
                $text = (string) ($row[$field] ?? '');
                if ($text !== '' && $this->guard->rejectReason($text) !== null) {
                    continue 2;
                }
            }
            $clean[] = $row;
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $pack
     */
    private function inventedNumber(string $text, array $metrics, array $pack): bool
    {
        if (preg_match_all('/\d+/', $text, $matches) === 0) {
            return false;
        }

        $allowed = ['7', '14', '30'];
        foreach ($metrics as $value) {
            if (is_int($value) || is_float($value)) {
                $allowed[] = (string) (int) $value;
            }
        }
        foreach (['findings', 'attention', 'strengths', 'trends'] as $key) {
            foreach (is_array($pack[$key] ?? null) ? $pack[$key] : [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $metric = $row['metric']['value'] ?? null;
                if (is_numeric($metric)) {
                    $allowed[] = (string) (int) $metric;
                }
                if (isset($row['metric']['from'])) {
                    $allowed[] = (string) (int) $row['metric']['from'];
                }
                if (isset($row['metric']['to'])) {
                    $allowed[] = (string) (int) $row['metric']['to'];
                }
            }
        }

        foreach ($matches[0] as $number) {
            if (! in_array($number, $allowed, true) && (int) $number > 3) {
                return true;
            }
        }

        return false;
    }
}
