<?php

namespace App\Models;

use App\Enums\CommitmentConfidence;
use App\Enums\CommitmentDeadlinePrecision;
use App\Enums\CommitmentEffectiveStatus;
use App\Enums\CommitmentLifecycleStatus;
use App\Enums\CommitmentSourceType;
use Database\Factories\CommitmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'person_id',
    'person_name_raw',
    'unresolved_person',
    'project_id',
    'meeting_id',
    'organization_id',
    'meeting_analysis_id',
    'merged_into_id',
    'title',
    'expected_result',
    'description',
    'deadline_raw',
    'deadline_at',
    'deadline_precision',
    'status',
    'lifecycle_status',
    'confidence',
    'source_type',
    'source_id',
    'source_reference',
    'fingerprint',
    'completion_note',
    'last_notified_status',
    'last_notified_at',
    'owner_edited_at',
    'detected_at',
    'confirmed_at',
    'completed_at',
    'cancelled_at',
    'cancel_reason',
    'metadata',
])]
class Commitment extends Model
{
    /** @use HasFactory<CommitmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unresolved_person' => 'boolean',
            'deadline_at' => 'datetime',
            'deadline_precision' => CommitmentDeadlinePrecision::class,
            'status' => CommitmentEffectiveStatus::class,
            'lifecycle_status' => CommitmentLifecycleStatus::class,
            'confidence' => CommitmentConfidence::class,
            'source_type' => CommitmentSourceType::class,
            'source_reference' => 'array',
            'last_notified_at' => 'datetime',
            'owner_edited_at' => 'datetime',
            'detected_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function meetingAnalysis(): BelongsTo
    {
        return $this->belongsTo(MeetingAnalysis::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(CommitmentEvidence::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(CommitmentStatusHistory::class);
    }
}
