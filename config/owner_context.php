<?php

return [

    'disk' => 'local',

    'directory' => 'owner-context',

    'queue' => env('OWNER_CONTEXT_QUEUE', 'memory'),

    /*
    | Structured fixtures that contain the marker "owner-context: structured"
    | are parsed without a model call. Unstructured uploads use the analysis model.
    */
    'use_ai' => env('OWNER_CONTEXT_USE_AI', true),

    'chunk_chars' => 6000,

    'max_chunks' => 6,

    'max_items' => 400,

    'auto_accept_confidence' => 0.85,

    'evidence_max' => 280,

    'retrieval_limit' => 6,

    'prompt_budget_chars' => 700,

];
