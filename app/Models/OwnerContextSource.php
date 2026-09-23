<?php

namespace App\Models;

use App\Enums\OwnerContextSourceStatus;
use App\Enums\OwnerContextSourceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'name',
    'source_type',
    'source_date',
    'original_filename',
    'storage_path',
    'status',
    'metadata',
])]
class OwnerContextSource extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => OwnerContextSourceType::class,
            'source_date' => 'date',
            'status' => OwnerContextSourceStatus::class,
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OwnerContextItem::class, 'source_id');
    }
}
