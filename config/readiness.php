<?php

return [
    'scheduler_stale_seconds' => (int) env('LAVR_SCHEDULER_STALE_SECONDS', 300),
    'queue_stale_seconds' => (int) env('LAVR_QUEUE_STALE_SECONDS', 900),
    'disk_warn_percent' => (int) env('LAVR_DISK_WARN_PERCENT', 90),
    'heartbeat_cache_key' => 'lavr:scheduler:heartbeat',
    'queue_heartbeat_cache_key' => 'lavr:queue:heartbeat',
    'backup_directory' => storage_path('backups'),
    'owner_password_rotation_warn' => true,
];
