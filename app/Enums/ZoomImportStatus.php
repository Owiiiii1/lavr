<?php

namespace App\Enums;

enum ZoomImportStatus: string
{
    case Pending = 'pending';
    case Downloading = 'downloading';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case BlockedAuth = 'blocked_auth';
    case TranscriptUnavailable = 'transcript_unavailable';
}
