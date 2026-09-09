<?php

namespace App\Enums;

enum OwnerLocale: string
{
    case Uk = 'uk';
    case En = 'en';
    case Ru = 'ru';

    public static function default(): self
    {
        return self::Uk;
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(
            fn (self $locale): string => $locale->value,
            self::cases(),
        );
    }

    public static function fromMixed(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $code = is_string($value) ? strtolower(trim($value)) : '';

        return self::tryFrom($code) ?? self::default();
    }

    public function bcp47(): string
    {
        return match ($this) {
            self::Uk => 'uk-UA',
            self::En => 'en-US',
            self::Ru => 'ru-RU',
        };
    }

    public function englishName(): string
    {
        return match ($this) {
            self::Uk => 'Ukrainian',
            self::En => 'English',
            self::Ru => 'Russian',
        };
    }
}
