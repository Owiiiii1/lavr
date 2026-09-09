<?php

namespace App\Models;

use App\Enums\PersonIdentityType;
use Database\Factories\PersonIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'person_id',
    'type',
    'value',
    'normalized_value',
    'is_primary',
    'verified_at',
    'metadata',
])]
class PersonIdentity extends Model
{
    /** @use HasFactory<PersonIdentityFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PersonIdentityType::class,
            'is_primary' => 'boolean',
            'verified_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
