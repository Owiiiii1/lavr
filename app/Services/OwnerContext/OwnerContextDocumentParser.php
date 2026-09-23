<?php

namespace App\Services\OwnerContext;

use App\Enums\OwnerContextCategory;
use App\Enums\OwnerContextFactClass;
use App\Enums\OwnerContextScopeType;
use App\Enums\OwnerContextSensitivity;
use App\Services\OwnerContext\DTO\OwnerContextCandidate;

/**
 * Splits a source into one claim per line.
 * Bracket lines are exact. Headings classify the bullets under them.
 * A document that contains "owner-context: structured" is not sent to the model.
 */
final class OwnerContextDocumentParser
{
    public const STRUCTURED_MARKER = 'owner-context: structured';

    /**
     * @return list<OwnerContextCandidate>
     */
    public function parse(string $text): array
    {
        $factClass = OwnerContextFactClass::Current;
        $category = OwnerContextCategory::Other;
        $sensitivity = OwnerContextSensitivity::Normal;
        $scopeType = OwnerContextScopeType::Owner;
        $items = [];

        foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '<!--') || str_starts_with($trimmed, '#')) {
                if (str_starts_with($trimmed, '#')) {
                    [$factClass, $category, $sensitivity, $scopeType] = $this->heading($trimmed);
                }

                continue;
            }

            $bullet = preg_replace('/^[-*]\s+/', '', $trimmed) ?? $trimmed;
            $candidate = $this->bracket($bullet) ?? $this->plain($bullet, $factClass, $category, $sensitivity, $scopeType);

            if ($candidate !== null) {
                $items[] = $candidate;
            }
        }

        return $items;
    }

    public function isStructured(string $text): bool
    {
        return str_contains($text, self::STRUCTURED_MARKER);
    }

    /**
     * @return array{0: OwnerContextFactClass, 1: OwnerContextCategory, 2: OwnerContextSensitivity, 3: OwnerContextScopeType}
     */
    private function heading(string $line): array
    {
        $heading = mb_strtolower(trim($line, "# \t"));
        $factClass = OwnerContextFactClass::Current;
        $category = OwnerContextCategory::Other;
        $sensitivity = OwnerContextSensitivity::Normal;
        $scope = OwnerContextScopeType::Owner;

        if ($this->has($heading, ['to verify', 'verify', 'перевір', 'провер'])) {
            $factClass = OwnerContextFactClass::ToVerify;
        } elseif ($this->has($heading, ['historical', 'former', 'outdated', 'історич', 'историч'])) {
            $factClass = OwnerContextFactClass::Historical;
        } elseif ($this->has($heading, ['analysis', 'strength', 'weakness', 'аналіз', 'анализ'])) {
            $factClass = OwnerContextFactClass::Analysis;
        } elseif ($this->has($heading, ['fact'])) {
            $factClass = OwnerContextFactClass::Fact;
        }

        if ($this->has($heading, ['communication', 'комуніка', 'коммуника'])) {
            $category = OwnerContextCategory::Communication;
        } elseif ($this->has($heading, ['goal', 'ціл', 'цел'])) {
            $category = OwnerContextCategory::CeoGoal;
        } elseif ($this->has($heading, ['operating rule', 'ai rule', 'protocol', 'правил'])) {
            $category = OwnerContextCategory::CeoOperatingRule;
        } elseif ($this->has($heading, ['development', 'strength', 'weakness'])) {
            $category = OwnerContextCategory::CeoDevelopment;
        } elseif ($this->has($heading, ['business', 'company', 'компан', 'бізнес', 'бизнес'])) {
            $category = OwnerContextCategory::BusinessContext;
            $scope = OwnerContextScopeType::Business;
        } elseif ($this->has($heading, ['priority', 'пріоритет', 'приоритет'])) {
            $category = OwnerContextCategory::Priority;
        } elseif ($this->has($heading, ['role', 'team', 'employee', 'команд'])) {
            $category = OwnerContextCategory::RoleContext;
            $scope = OwnerContextScopeType::Person;
        } elseif ($this->has($heading, ['identity', 'profile', 'language', 'мов'])) {
            $category = OwnerContextCategory::Identity;
        } elseif ($this->has($heading, ['personal', 'особист', 'личн'])) {
            $category = OwnerContextCategory::PersonalConstraint;
            $sensitivity = OwnerContextSensitivity::Private;
        }

        if ($this->has($heading, ['project', 'проєкт', 'проект'])) {
            $scope = OwnerContextScopeType::Project;
        }

        if ($this->has($heading, ['restricted', 'health', 'family', 'здоров', 'сім', 'семь'])) {
            $sensitivity = OwnerContextSensitivity::Restricted;
        }

        return [$factClass, $category, $sensitivity, $scope];
    }

    private function bracket(string $line): ?OwnerContextCandidate
    {
        if (preg_match('/^\[([A-Za-z_]+)\|([A-Za-z_]+)\|([^|\]]+)\|([A-Za-z_]+)\|([0-9]*\.?[0-9]+)\]\s+(.+)$/u', $line, $match) !== 1) {
            return null;
        }

        $factClass = OwnerContextFactClass::tryFromLoose($match[1]);
        $category = OwnerContextCategory::tryFromLoose($match[2]);
        $sensitivity = OwnerContextSensitivity::tryFromLoose($match[4]);

        if ($factClass === null || $category === null || $sensitivity === null) {
            return null;
        }

        [$scopeType, $label] = $this->scopeToken($match[3]);

        return $this->make(
            $match[6],
            $category,
            $factClass,
            $scopeType,
            $label,
            $sensitivity,
            (float) $match[5],
        );
    }

    private function plain(
        string $line,
        OwnerContextFactClass $factClass,
        OwnerContextCategory $category,
        OwnerContextSensitivity $sensitivity,
        OwnerContextScopeType $scopeType,
    ): ?OwnerContextCandidate {
        return $this->make($line, $category, $factClass, $scopeType, null, $sensitivity, null);
    }

    /**
     * @return array{0: OwnerContextScopeType, 1: ?string}
     */
    private function scopeToken(string $token): array
    {
        $token = trim($token);

        if (str_contains($token, ':')) {
            [$type, $label] = explode(':', $token, 2);
            $scope = OwnerContextScopeType::tryFromLoose($type) ?? OwnerContextScopeType::Owner;

            return [$scope, trim($label) !== '' ? trim($label) : null];
        }

        return [OwnerContextScopeType::tryFromLoose($token) ?? OwnerContextScopeType::Owner, null];
    }

    private function make(
        string $value,
        OwnerContextCategory $category,
        OwnerContextFactClass $factClass,
        OwnerContextScopeType $scopeType,
        ?string $label,
        OwnerContextSensitivity $sensitivity,
        ?float $confidence,
    ): ?OwnerContextCandidate {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        $length = mb_strlen($value);

        if ($length < 8 || $length > 500 || $this->isFiller($value)) {
            return null;
        }

        $excerpt = mb_substr($value, 0, (int) config('owner_context.evidence_max', 280));

        return new OwnerContextCandidate(
            value: $value,
            category: $category,
            factClass: $factClass,
            scopeType: $scopeType,
            scopeLabel: $label,
            sensitivity: $sensitivity,
            confidence: $confidence,
            evidenceExcerpt: $excerpt,
        );
    }

    private function isFiller(string $value): bool
    {
        return preg_match('/^(great job|you can do it|stay motivated|keep going)\b/iu', $value) === 1;
    }

    /**
     * @param  list<string>  $needles
     */
    private function has(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
