<?php

return [

    'oauth_token_url' => env('ZOOM_OAUTH_TOKEN_URL', 'https://zoom.us/oauth/token'),

    'api_base_url' => env('ZOOM_API_BASE_URL', 'https://api.zoom.us/v2'),

    'timeout' => (int) env('ZOOM_HTTP_TIMEOUT', 15),

    'connect_timeout' => (int) env('ZOOM_HTTP_CONNECT_TIMEOUT', 5),

    'download_timeout' => (int) env('ZOOM_DOWNLOAD_TIMEOUT', 30),

    'token_skew_seconds' => (int) env('ZOOM_TOKEN_SKEW_SECONDS', 60),

    'replay_window_seconds' => (int) env('ZOOM_WEBHOOK_REPLAY_WINDOW', 300),

    'max_webhook_bytes' => (int) env('ZOOM_WEBHOOK_MAX_BYTES', 262144),

    'queue' => env('ZOOM_QUEUE', 'default'),

    'job_timeout' => (int) env('ZOOM_JOB_TIMEOUT', 120),

    'job_tries' => (int) env('ZOOM_JOB_TRIES', 6),

    'scopes' => [
        'cloud_recording:read:meeting_transcript:admin',
        'cloud_recording:read:list_recording_files:admin',
        'user:read:user:admin',
    ],

    'trusted_download_hosts' => [
        'zoom.us',
        'zoom.com',
        'zoomgov.com',
    ],

    'trusted_download_host_suffixes' => [
        '.zoom.us',
        '.zoom.com',
        '.zoomgov.com',
    ],

];
