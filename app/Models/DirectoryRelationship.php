<?php

namespace App\Models;

use App\Enums\DirectoryPartyType;
use App\Enums\DirectoryRelationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'subject_type',
    'subject_id',
    'relation_type',
    'object_type',
    'object_id',
    'notes',
    'metadata',
])]
class DirectoryRelationship extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_type' => DirectoryPartyType::class,
            'object_type' => DirectoryPartyType::class,
            'relation_type' => DirectoryRelationType::class,
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
