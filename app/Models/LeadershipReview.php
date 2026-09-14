<?php

namespace App\Models;

use App\Enums\LeadershipReviewStatus;
use App\Enums\LeadershipReviewType;
use Database\Factories\LeadershipReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadershipReview extends Model
{
    /** @use HasFactory<LeadershipReviewFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'review_type',
        'period_start',
        'period_end',
        'project_id',
        'person_id',
        'meeting_id',
        'status',
        'summary',
        'metrics_json',
        'findings_json',
        'source_snapshot_json',
        'generated_at',
        'generated_by',
        'origin',
        'run_key',
        'automation_run_id',
        'timezone',
        'locale',
        'delivery_status',
        'delivered_at',
        'safe_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'review_type' => LeadershipReviewType::class,
            'status' => LeadershipReviewStatus::class,
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'generated_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'metrics_json' => 'array',
            'findings_json' => 'array',
            'source_snapshot_json' => 'array',
        ];
    }

    protected static function newFactory(): LeadershipReviewFactory
    {
        return LeadershipReviewFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class);
    }
}
