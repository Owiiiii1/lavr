<?php

namespace App\Enums;

enum OwnerContextSourceType: string
{
    case Upload = 'upload';
    case Manual = 'manual';
    case System = 'system';
}
