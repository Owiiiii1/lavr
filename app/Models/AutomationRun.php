<?php

namespace App\Models;

use App\Enums\AutomationRunOutcome;
use App\Enums\AutomationType;
use Database\Factories\AutomationRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class AutomationRun extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'automation_type',
        'automation_id',
        'run_key',
        'scheduled_for',
        'started_at',
        'finished_at',
        'status',
        'attempt',
        'outcome_code',
        'delivery_status',
        'delivery_key',
        'metrics',
        'safe_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'automation_type' => AutomationType::class,
            'status' => AutomationRunOutcome::class,
            'scheduled_for' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'metrics' => 'array',
            'attempt' => 'integer',
        ];
    }

    protected static function newFactory(): AutomationRunFactory
    {
        return AutomationRunFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function durationMs(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInMilliseconds($this->finished_at);
    }

    public static function latestFor(AutomationType $type, int $automationId): ?self
    {
        if (! Schema::hasTable('automation_runs')) {
            return null;
        }

        return self::query()
            ->where('automation_type', $type)
            ->where('automation_id', $automationId)
            ->orderByDesc('id')
            ->first();
    }
}
