<?php

namespace App\Services\OwnerContext;

use App\Enums\OwnerContextCategory;
use App\Enums\OwnerContextFactClass;
use App\Enums\OwnerContextScopeType;
use App\Enums\OwnerContextSensitivity;
use App\Services\Memory\StructuredJsonParser;
use App\Services\OwnerContext\DTO\OwnerContextCandidate;
use Throwable;

final class OwnerContextAiParser
{
    /**
     * @return list<OwnerContextCandidate>
     */
    public function parse(string $text): array
    {
        try {
            $payload = StructuredJsonParser::objectFromText($text);
        } catch (Throwable) {
            return [];
        }

        $rows = $payload['items'] ?? null;

        if (! is_array($rows)) {
            return [];
        }

        $items = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $candidate = $this->row($row);

            if ($candidate !== null) {
                $items[] = $candidate;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function row(array $row): ?OwnerContextCandidate
    {
        $factClass = OwnerContextFactClass::tryFromLoose($row['fact_class'] ?? null);
        $category = OwnerContextCategory::tryFromLoose($row['category'] ?? null) ?? OwnerContextCategory::Other;
        $scopeType = OwnerContextScopeType::tryFromLoose($row['scope_type'] ?? null) ?? OwnerContextScopeType::Owner;
        $sensitivity = OwnerContextSensitivity::tryFromLoose($row['sensitivity'] ?? null) ?? OwnerContextSensitivity::Normal;
        $value = trim((string) ($row['value'] ?? ''));

        if ($factClass === null || mb_strlen($value) < 8 || mb_strlen($value) > 500) {
            return null;
        }

        $label = isset($row['scope_label']) ? trim((string) $row['scope_label']) : '';
        $confidence = isset($row['confidence']) && is_numeric($row['confidence'])
            ? max(0.0, min(1.0, (float) $row['confidence']))
            : null;
        $excerpt = trim((string) ($row['evidence_excerpt'] ?? ''));

        return new OwnerContextCandidate(
            value: $value,
            category: $category,
            factClass: $factClass,
            scopeType: $scopeType,
            scopeLabel: $label !== '' ? $label : null,
            sensitivity: $sensitivity,
            confidence: $confidence,
            evidenceExcerpt: $excerpt !== '' ? mb_substr($excerpt, 0, (int) config('owner_context.evidence_max', 280)) : mb_substr($value, 0, 180),
        );
    }
}
