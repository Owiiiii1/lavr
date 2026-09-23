<?php

namespace App\Models;

use App\Enums\OwnerContextCategory;
use App\Enums\OwnerContextFactClass;
use App\Enums\OwnerContextItemStatus;
use App\Enums\OwnerContextScopeType;
use App\Enums\OwnerContextSensitivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'source_id',
    'category',
    'scope_type',
    'scope_id',
    'fact_class',
    'value',
    'structured_value',
    'status',
    'sensitivity',
    'effective_from',
    'effective_to',
    'supersedes_id',
    'confidence',
    'evidence_excerpt',
    'source_reference',
    'fingerprint',
    'metadata',
])]
class OwnerContextItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => OwnerContextCategory::class,
            'scope_type' => OwnerContextScopeType::class,
            'fact_class' => OwnerContextFactClass::class,
            'status' => OwnerContextItemStatus::class,
            'sensitivity' => OwnerContextSensitivity::class,
            'structured_value' => 'array',
            'source_reference' => 'array',
            'metadata' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'confidence' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(OwnerContextSource::class, 'source_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }
}
