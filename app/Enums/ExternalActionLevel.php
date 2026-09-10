<?php

namespace App\Enums;

enum ExternalActionLevel: string
{
    case Read = 'read';
    case Suggest = 'suggest';
    case Draft = 'draft';
    case Execute = 'execute';

    public function allowsWrite(): bool
    {
        return $this === self::Execute;
    }
}
