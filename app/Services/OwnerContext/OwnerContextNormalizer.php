<?php

namespace App\Services\OwnerContext;

use App\Enums\OwnerContextCategory;
use App\Enums\OwnerContextFactClass;
use App\Enums\OwnerContextScopeType;

final class OwnerContextNormalizer
{
    public static function value(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = (string) preg_replace('/\s+/u', ' ', $normalized);
        $normalized = rtrim($normalized, " \t.");

        return mb_substr($normalized, 0, 500);
    }

    public static function fingerprint(
        int $userId,
        OwnerContextCategory $category,
        OwnerContextScopeType $scopeType,
        ?int $scopeId,
        ?string $scopeLabel,
        OwnerContextFactClass $factClass,
        string $value,
    ): string {
        $scope = $scopeId !== null
            ? (string) $scopeId
            : 'label:'.self::value((string) $scopeLabel);

        return hash('sha256', implode('|', [
            $userId,
            $category->value,
            $scopeType->value,
            $scope,
            $factClass->value,
            self::value($value),
        ]));
    }
}
