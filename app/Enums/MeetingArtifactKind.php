<?php

namespace App\Enums;

enum MeetingArtifactKind: string
{
    case OriginalFile = 'original_file';
    case OriginalText = 'original_text';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $kind): string => $kind->value, self::cases());
    }
}
