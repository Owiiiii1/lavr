<?php

namespace App\Models;

use App\Enums\OperationalEventStatus;
use App\Enums\OperationalEventType;
use App\Enums\OperationalSeverity;
use Database\Factories\OperationalEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'event_type',
    'occurred_at',
    'source_type',
    'source_id',
    'source_external_id',
    'person_id',
    'project_id',
    'meeting_id',
    'commitment_id',
    'severity',
    'fingerprint',
    'payload_json',
    'status',
    'confidence',
    'evidence_pointer',
])]
class OperationalEvent extends Model
{
    /** @use HasFactory<OperationalEventFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'observed',
        'severity' => 'normal',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => OperationalEventType::class,
            'status' => OperationalEventStatus::class,
            'severity' => OperationalSeverity::class,
            'occurred_at' => 'immutable_datetime',
            'payload_json' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function commitment(): BelongsTo
    {
        return $this->belongsTo(Commitment::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(ProactiveProposal::class);
    }
}
