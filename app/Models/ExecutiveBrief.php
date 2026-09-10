<?php

namespace App\Models;

use App\Enums\ExecutiveBriefStatus;
use App\Enums\ExecutiveBriefType;
use Database\Factories\ExecutiveBriefFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutiveBrief extends Model
{
    /** @use HasFactory<ExecutiveBriefFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'brief_type',
        'origin',
        'period_start',
        'period_end',
        'generated_for',
        'timezone',
        'locale',
        'status',
        'priority_score',
        'summary',
        'sections_json',
        'source_snapshot_json',
        'automation_run_id',
        'regenerated_from_id',
        'run_key',
        'generated_at',
        'delivered_at',
        'delivery_status',
        'safe_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'brief_type' => ExecutiveBriefType::class,
            'status' => ExecutiveBriefStatus::class,
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'generated_for' => 'date',
            'generated_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'sections_json' => 'array',
            'source_snapshot_json' => 'array',
            'priority_score' => 'integer',
            'automation_run_id' => 'integer',
            'regenerated_from_id' => 'integer',
        ];
    }

    protected static function newFactory(): ExecutiveBriefFactory
    {
        return ExecutiveBriefFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function regeneratedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'regenerated_from_id');
    }

    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class);
    }
}
