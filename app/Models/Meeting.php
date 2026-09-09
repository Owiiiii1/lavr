<?php

namespace App\Models;

use App\Enums\MeetingAnalysisStatus;
use App\Enums\MeetingSourceType;
use App\Enums\MeetingStatus;
use Database\Factories\MeetingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'project_id',
    'organization_id',
    'current_analysis_id',
    'title',
    'meeting_type',
    'started_at',
    'ended_at',
    'timezone',
    'location',
    'source_type',
    'source_external_id',
    'status',
    'analysis_status',
    'source_language',
    'summary',
    'notes',
    'metadata',
])]
class Meeting extends Model
{
    /** @use HasFactory<MeetingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => MeetingSourceType::class,
            'status' => MeetingStatus::class,
            'analysis_status' => MeetingAnalysisStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function currentAnalysis(): BelongsTo
    {
        return $this->belongsTo(MeetingAnalysis::class, 'current_analysis_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(MeetingArtifact::class);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(MeetingAnalysis::class);
    }
}
