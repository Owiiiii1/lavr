<?php

namespace App\Services\Reports;

final class MailTextNormalizer
{
    /**
     * Marketing mail pads snippets with zero-width joiners and soft hyphens to
     * stretch the preview line. They read as noise and waste phrasing tokens.
     */
    private const INVISIBLE = '/[\x{00AD}\x{200B}-\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u';

    public static function normalize(string $text): string
    {
        $text = preg_replace(self::INVISIBLE, '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    public static function snippet(string $text, int $max = 180): string
    {
        $text = self::normalize($text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }
}
