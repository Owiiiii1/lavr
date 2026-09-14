<?php

return [

    'prompt_version' => 'leadership_review_v1',

    'default_period_days' => 30,

    'min_sample' => 3,

    'workload_active_threshold' => 8,

    'workload_same_window' => 5,

    'owner_dependency_share' => 0.5,

    'telegram_max_chars' => 1200,

    'weekly_local_time' => env('LEADERSHIP_REVIEW_WEEKLY_TIME', '09:00'),

    'weekly_weekday' => (int) env('LEADERSHIP_REVIEW_WEEKDAY', 1),

];
