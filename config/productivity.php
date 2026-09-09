<?php

return [

    /*
    | Conservative Phase B.2 defaults. Ordinary users must opt in.
    | Proactive suggestions are separate from reminder_due / brief_ready.
    */
    'proactive' => [
        'max_per_day' => 3,
        'cooldown_hours' => 4,
        'approach_hours' => 2,
    ],

    'briefs' => [
        'daily_local_time' => '08:00',
        'evening_local_time' => '20:00',
        'weekly_weekday' => 7,
        'weekly_local_time' => '18:00',
        'max_words' => 180,

        /*
        | Reasoning models spend most of the output budget on hidden thinking
        | tokens, so a tight limit returns a truncated brief that we discard.
        */
        'phrasing_max_tokens' => 1600,
    ],

    'tasks' => [
        'title_max' => 240,
        'description_max' => 4000,
        'metadata_max_bytes' => 4096,
    ],

    'notifications' => [
        'title_max' => 160,
        'body_max' => 800,
        'push_body_limit' => 120,
    ],

];
