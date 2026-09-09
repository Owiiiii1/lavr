<?php

namespace App\Support;

use App\Enums\OwnerLocale;
use Illuminate\Support\Facades\Log;

final class OwnerCopy
{
    /**
     * @param  array<string, scalar>  $replace
     */
    public static function get(string $key, OwnerLocale $locale, array $replace = []): string
    {
        $line = trans($key, $replace, $locale->value);

        if ($line === $key) {
            $line = trans($key, $replace, OwnerLocale::default()->value);
        }

        if ($line === $key) {
            Log::warning('owner_locale_missing_key', [
                'key' => $key,
                'locale' => $locale->value,
            ]);
        }

        return $line;
    }
}
