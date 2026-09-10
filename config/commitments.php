<?php

return [

    'due_soon_hours' => (int) env('COMMITMENTS_DUE_SOON_HOURS', 48),

    'max_excerpt_chars' => (int) env('COMMITMENTS_MAX_EXCERPT_CHARS', 280),

    'title_max' => 120,

    'confirmed_recent_limit' => (int) env('COMMITMENTS_CONFIRMED_RECENT_LIMIT', 8),

];
