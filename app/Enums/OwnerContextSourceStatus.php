<?php

namespace App\Enums;

enum OwnerContextSourceStatus: string
{
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Archived = 'archived';
}
