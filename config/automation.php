<?php

return [

    'stale_processing_minutes' => (int) env('AUTOMATION_STALE_MINUTES', 30),

    'prompt_version' => 'scheduled_report_v1',

    'max_attempts' => 3,

    'skip_if_empty_default' => false,

    'owner_failure_notify_after' => 3,

];
