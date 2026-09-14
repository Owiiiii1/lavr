<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'current_step',
    'steps_json',
    'banner_dismissed',
    'completed_at',
])]
class BusinessMapProgress extends Model
{
    protected $table = 'business_map_progresses';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'steps_json' => 'array',
            'banner_dismissed' => 'boolean',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
