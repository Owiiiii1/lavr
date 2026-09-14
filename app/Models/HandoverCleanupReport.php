<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'operation_id',
    'selectors_json',
    'dry_run',
    'executed',
    'plan_json',
    'result_json',
])]
class HandoverCleanupReport extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selectors_json' => 'array',
            'plan_json' => 'array',
            'result_json' => 'array',
            'dry_run' => 'boolean',
            'executed' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
