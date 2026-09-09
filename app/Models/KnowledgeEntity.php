<?php

namespace App\Models;

use App\Enums\KnowledgeEntityStatus;
use App\Enums\KnowledgeEntityType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'type',
    'name',
    'normalized_name',
    'summary',
    'status',
    'project_id',
    'canonical_type',
    'canonical_id',
    'confidence',
    'metadata',
])]
class KnowledgeEntity extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => KnowledgeEntityType::class,
            'status' => KnowledgeEntityStatus::class,
            'confidence' => 'float',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(KnowledgeEntityAlias::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(KnowledgeEntitySource::class);
    }

    public function outgoingRelationships(): HasMany
    {
        return $this->hasMany(KnowledgeRelationship::class, 'source_entity_id');
    }

    public function incomingRelationships(): HasMany
    {
        return $this->hasMany(KnowledgeRelationship::class, 'target_entity_id');
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeEvent::class, 'knowledge_event_entities')
            ->withPivot(['role'])
            ->withTimestamps();
    }
}
