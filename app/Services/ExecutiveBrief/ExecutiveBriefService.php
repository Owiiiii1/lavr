<?php

namespace App\Services\ExecutiveBrief;

use App\Enums\ExecutiveBriefStatus;
use App\Enums\ExecutiveBriefType;
use App\Models\ExecutiveBrief;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ExecutiveBriefService
{
    public function __construct(
        private readonly ExecutiveBriefGenerator $generator,
    ) {}

    public function generateNow(User $user, ?int $regeneratedFromId = null): ExecutiveBrief
    {
        return $this->generator->generate(
            $user,
            ExecutiveBriefType::Morning,
            CarbonImmutable::now('UTC'),
            'manual',
            $regeneratedFromId,
            $this->deliveryFor($user),
        );
    }

    public function latestForToday(User $user): ?ExecutiveBrief
    {
        $timezone = (string) ($user->timezone ?: 'UTC');
        $date = CarbonImmutable::now('UTC')->setTimezone($timezone)->toDateString();

        return ExecutiveBrief::query()
            ->where('user_id', $user->id)
            ->where('brief_type', ExecutiveBriefType::Morning)
            ->whereDate('generated_for', $date)
            ->whereIn('status', [ExecutiveBriefStatus::Ready, ExecutiveBriefStatus::Partial])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array{date?: string, type?: string, status?: string}  $filters
     * @return LengthAwarePaginator<int, ExecutiveBrief>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = ExecutiveBrief::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id');

        $type = ExecutiveBriefType::tryFrom((string) ($filters['type'] ?? ''));
        if ($type !== null) {
            $query->where('brief_type', $type);
        }

        $status = ExecutiveBriefStatus::tryFrom((string) ($filters['status'] ?? ''));
        if ($status !== null) {
            $query->where('status', $status);
        }

        $date = trim((string) ($filters['date'] ?? ''));
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $query->whereDate('generated_for', $date);
        }

        return $query->paginate(40)->withQueryString();
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(ExecutiveBrief $brief): array
    {
        $sections = is_array($brief->sections_json) ? $brief->sections_json : [];
        $visible = [];
        foreach ($sections as $name => $rows) {
            if (! is_array($rows) || $rows === []) {
                continue;
            }
            $visible[$name] = array_values($rows);
        }

        $snapshot = is_array($brief->source_snapshot_json) ? $brief->source_snapshot_json : [];
        unset($snapshot['bodies'], $snapshot['emails'], $snapshot['transcripts']);

        return [
            'id' => (int) $brief->id,
            'brief_type' => $brief->brief_type instanceof ExecutiveBriefType ? $brief->brief_type->value : (string) $brief->brief_type,
            'origin' => (string) $brief->origin,
            'status' => $brief->status instanceof ExecutiveBriefStatus ? $brief->status->value : (string) $brief->status,
            'priority_score' => $brief->priority_score,
            'summary' => (string) ($brief->summary ?? ''),
            'sections' => $visible,
            'source_snapshot' => [
                'sources_attempted' => (int) ($snapshot['sources_attempted'] ?? 0),
                'sources_succeeded' => (int) ($snapshot['sources_succeeded'] ?? 0),
                'sources_failed' => (int) ($snapshot['sources_failed'] ?? 0),
                'items_collected' => (int) ($snapshot['items_collected'] ?? 0),
                'items_rendered' => (int) ($snapshot['items_rendered'] ?? 0),
                'errors' => is_array($snapshot['errors'] ?? null) ? array_values($snapshot['errors']) : [],
                'blocked' => is_array($snapshot['blocked'] ?? null) ? $snapshot['blocked'] : [],
                'freshness' => is_array($snapshot['freshness'] ?? null) ? $snapshot['freshness'] : [],
                'ai_used' => (bool) ($snapshot['ai_used'] ?? false),
            ],
            'timezone' => (string) $brief->timezone,
            'locale' => (string) $brief->locale,
            'generated_for' => optional($brief->generated_for)?->toDateString(),
            'generated_at' => optional($brief->generated_at)?->toIso8601String(),
            'delivered_at' => optional($brief->delivered_at)?->toIso8601String(),
            'href' => '/lavr/briefs/'.$brief->id,
            'copy_text' => $this->copyText($brief->summary, $visible),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeSummary(ExecutiveBrief $brief): array
    {
        $sections = is_array($brief->sections_json) ? $brief->sections_json : [];
        $attention = is_array($sections['attention'] ?? null) ? $sections['attention'] : [];

        return [
            'id' => (int) $brief->id,
            'brief_type' => $brief->brief_type instanceof ExecutiveBriefType ? $brief->brief_type->value : (string) $brief->brief_type,
            'status' => $brief->status instanceof ExecutiveBriefStatus ? $brief->status->value : (string) $brief->status,
            'priority_count' => count($attention),
            'summary' => (string) ($brief->summary ?? ''),
            'generated_for' => optional($brief->generated_for)?->toDateString(),
            'generated_at' => optional($brief->generated_at)?->toIso8601String(),
            'href' => '/lavr/briefs/'.$brief->id,
        ];
    }

    /**
     * @return array{telegram: bool, inbox: bool}
     */
    public function deliveryFor(User $user): array
    {
        $settings = $user->productivitySetting;

        return [
            'telegram' => (bool) ($settings?->morning_brief_telegram ?? true),
            'inbox' => (bool) ($settings?->morning_brief_inbox ?? true),
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $sections
     */
    private function copyText(?string $summary, array $sections): string
    {
        $lines = [trim((string) $summary)];
        foreach ($sections as $rows) {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $line = trim((string) ($row['summary'] ?? $row['title'] ?? ''));
                if ($line !== '') {
                    $lines[] = '• '.$line;
                }
            }
        }

        return trim(implode("\n", array_filter($lines)));
    }
}
