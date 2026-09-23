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

    /*
    | How many chunks one queue job handles before dispatching the next.
    | This keeps a single run inside the worker timeout. It is not a cap on the document.
    */
    'chunks_per_run' => (int) env('OWNER_CONTEXT_CHUNKS_PER_RUN', 8),

    /*
    | Emergency stop only. A normal import processes every chunk.
    | max_chunks (the old fixed limit of 6) is unused.
    */
    'hard_max_chunks' => (int) env('OWNER_CONTEXT_HARD_MAX_CHUNKS', 100),

    'max_items' => 400,

    'auto_accept_confidence' => 0.85,

    'evidence_max' => 280,

    'retrieval_limit' => 6,

    'prompt_budget_chars' => 700,

];
