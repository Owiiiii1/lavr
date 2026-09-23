<?php

namespace App\Services\OwnerContext;

use App\Enums\OwnerContextCategory;
use App\Enums\OwnerContextFactClass;
use App\Enums\OwnerContextItemStatus;
use App\Enums\OwnerContextScopeType;
use App\Enums\OwnerContextSensitivity;
use App\Models\OwnerContextItem;
use App\Models\User;
use App\Services\OwnerContext\DTO\OwnerContextQuery;

final class OwnerContextRetriever
{
    /**
     * @return array{prompt: string, ids: list<int>, lines: list<string>, precedence: string}
     */
    public function pack(User $user, OwnerContextQuery $query): array
    {
        $task = $query->task ?: $this->taskFromQuestion($query->question);
        $categories = $query->categories !== [] ? $query->categories : $this->categoriesFor($task);
        $includePrivate = $query->includePrivate || $this->privateRelevant($task);
        $limit = max(1, min(12, $query->limit > 0 ? $query->limit : (int) config('owner_context.retrieval_limit', 6)));

        $rows = OwnerContextItem::query()
            ->where('user_id', $user->id)
            ->where('status', OwnerContextItemStatus::Accepted)
            ->whereIn('fact_class', [
                OwnerContextFactClass::Fact,
                OwnerContextFactClass::Current,
                OwnerContextFactClass::Analysis,
            ])
            ->whereIn('category', $categories)
            ->where(function ($builder) use ($query): void {
                $builder->whereIn('scope_type', [OwnerContextScopeType::Owner, OwnerContextScopeType::Business]);

                if ($query->personId !== null) {
                    $builder->orWhere(function ($inner) use ($query): void {
                        $inner->where('scope_type', OwnerContextScopeType::Person)->where('scope_id', $query->personId);
                    });
                }

                if ($query->projectId !== null) {
                    $builder->orWhere(function ($inner) use ($query): void {
                        $inner->where('scope_type', OwnerContextScopeType::Project)->where('scope_id', $query->projectId);
                    });
                }

                if ($query->organizationId !== null) {
                    $builder->orWhere(function ($inner) use ($query): void {
                        $inner->where('scope_type', OwnerContextScopeType::Organization)->where('scope_id', $query->organizationId);
                    });
                }
            })
            ->when(! $query->includeRestricted, function ($builder): void {
                $builder->where('sensitivity', '!=', OwnerContextSensitivity::Restricted);
            })
            ->when(! $includePrivate, function ($builder): void {
                $builder->where('sensitivity', OwnerContextSensitivity::Normal);
            })
            ->orderByRaw("case fact_class when 'fact' then 0 when 'current' then 1 else 2 end")
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $lines = [];
        $ids = [];

        foreach ($rows as $row) {
            $ids[] = (int) $row->id;
            $lines[] = '- ['.$row->fact_class->value.'/'.$row->category->value.'] '.$row->value;
        }

        $precedence = 'Database records for people, projects, organizations, and commitments outrank these claims. Historical and unverified items are omitted.';
        $prompt = '';

        if ($lines !== []) {
            $prompt = "Owner context (task-scoped claims, not a biography). {$precedence}\n".implode("\n", $lines);
            $prompt = mb_substr($prompt, 0, (int) config('owner_context.prompt_budget_chars', 700));
        }

        return [
            'prompt' => $prompt,
            'ids' => $ids,
            'lines' => $lines,
            'precedence' => $precedence,
        ];
    }

    public function taskFromQuestion(?string $question): string
    {
        $text = mb_strtolower((string) $question);

        if ($this->has($text, ['overdue', 'просроч', 'простроч', 'what is overdue', 'що простроч', 'что просроч'])) {
            return 'operational';
        }

        if ($this->has($text, ['negotiat', 'переговор', 'contract', 'контракт'])) {
            return 'negotiation';
        }

        if ($this->has($text, ['how i handled', 'як я пров', 'как я пров', 'this meeting', 'цю зустріч', 'эту встречу'])) {
            return 'meeting_coaching';
        }

        return 'general';
    }

    /**
     * @return list<string>
     */
    private function categoriesFor(string $task): array
    {
        return match ($task) {
            'operational' => [OwnerContextCategory::CeoOperatingRule->value],
            'negotiation' => [
                OwnerContextCategory::CeoOperatingRule->value,
                OwnerContextCategory::BusinessContext->value,
                OwnerContextCategory::BusinessRule->value,
                OwnerContextCategory::Priority->value,
            ],
            'meeting_coaching' => [
                OwnerContextCategory::CeoGoal->value,
                OwnerContextCategory::CeoOperatingRule->value,
                OwnerContextCategory::CeoDevelopment->value,
            ],
            default => [
                OwnerContextCategory::Communication->value,
                OwnerContextCategory::CeoOperatingRule->value,
                OwnerContextCategory::Identity->value,
            ],
        };
    }

    private function privateRelevant(string $task): bool
    {
        return $task === 'negotiation' || $task === 'meeting_coaching';
    }

    /**
     * @param  list<string>  $needles
     */
    private function has(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
