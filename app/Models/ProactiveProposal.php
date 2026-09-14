<?php

namespace App\Models;

use App\Enums\ExternalActionLevel;
use App\Enums\OperationalSeverity;
use App\Enums\ProactiveProposalStatus;
use App\Enums\ProactiveProposalType;
use Database\Factories\ProactiveProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'operational_event_id',
    'person_id',
    'project_id',
    'commitment_id',
    'meeting_id',
    'proposal_type',
    'title',
    'rationale',
    'action_payload_json',
    'status',
    'requires_confirmation',
    'policy_level',
    'fingerprint',
    'severity',
    'dismiss_reason',
    'snoozed_until',
    'expires_at',
    'last_notified_at',
    'acted_at',
])]
class ProactiveProposal extends Model
{
    /** @use HasFactory<ProactiveProposalFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'requires_confirmation' => true,
        'policy_level' => 'suggest',
        'severity' => 'normal',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proposal_type' => ProactiveProposalType::class,
            'status' => ProactiveProposalStatus::class,
            'policy_level' => ExternalActionLevel::class,
            'severity' => OperationalSeverity::class,
            'action_payload_json' => 'array',
            'requires_confirmation' => 'boolean',
            'snoozed_until' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'last_notified_at' => 'immutable_datetime',
            'acted_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(OperationalEvent::class, 'operational_event_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function commitment(): BelongsTo
    {
        return $this->belongsTo(Commitment::class);
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(ProactiveProposalAudit::class);
    }

    public function isSnoozed(): bool
    {
        return $this->snoozed_until !== null && $this->snoozed_until->isFuture();
    }
}
