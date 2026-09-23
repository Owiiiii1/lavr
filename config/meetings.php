<?php

return [

    'disk' => env('MEETINGS_DISK', 'local'),

    'directory' => 'meetings',

    'queue' => env('MEETINGS_QUEUE', 'analysis'),

    'max_file_size_mb' => (int) env('MEETINGS_MAX_FILE_MB', 8),

    'max_paste_chars' => (int) env('MEETINGS_MAX_PASTE_CHARS', 1_500_000),

    'chunk_chars' => (int) env('MEETINGS_CHUNK_CHARS', 6000),

    'chunk_overlap_chars' => (int) env('MEETINGS_CHUNK_OVERLAP', 200),

    'max_chunks' => (int) env('MEETINGS_MAX_CHUNKS', 40),

    'chunk_ai_retries' => (int) env('MEETINGS_CHUNK_AI_RETRIES', 2),

    'max_evidence_chars' => (int) env('MEETINGS_MAX_EVIDENCE_CHARS', 280),

    'prompt_version' => env('MEETINGS_PROMPT_VERSION', 'meeting-intelligence-v2'),

    'job_timeout' => (int) env('MEETINGS_JOB_TIMEOUT', 180),

    'poll_seconds' => (int) env('MEETINGS_POLL_SECONDS', 3),

    'allowed_extensions' => [
        'txt',
        'vtt',
        'srt',
        'md',
    ],

    'allowed_mime_types' => [
        'text/plain',
        'text/vtt',
        'text/srt',
        'text/markdown',
        'text/x-markdown',
        'application/x-subrip',
        'application/octet-stream',
    ],

];
