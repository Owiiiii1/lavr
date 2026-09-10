<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'name',
    'normalized_name',
    'description',
    'category',
    'start_date',
    'end_date',
    'owner_person_id',
    'status',
    'metadata',
])]
class Project extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'metadata' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'project_conversations')
            ->withPivot(['attached_at', 'metadata']);
    }

    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class, 'project_topics')
            ->withPivot(['attached_at', 'metadata']);
    }

    public function memories(): BelongsToMany
    {
        return $this->belongsToMany(Memory::class, 'project_memories')
            ->withPivot(['attached_at', 'metadata']);
    }

    public function telegramGroups(): BelongsToMany
    {
        return $this->belongsToMany(TelegramGroup::class, 'project_groups')
            ->withPivot(['attached_at', 'metadata']);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function ownerPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'owner_person_id');
    }

    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'project_people')
            ->withPivot(['role', 'notes'])
            ->withTimestamps();
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'project_organizations')
            ->withPivot(['role', 'notes'])
            ->withTimestamps();
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function commitments(): HasMany
    {
        return $this->hasMany(Commitment::class);
    }

    public function sourceBindings(): HasMany
    {
        return $this->hasMany(ProjectSourceBinding::class);
    }
}
