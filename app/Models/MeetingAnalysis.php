<?php

namespace App\Models;

use App\Enums\MeetingAnalysisStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'meeting_id',
    'version',
    'provider',
    'model',
    'prompt_version',
    'status',
    'result_json',
    'summary',
    'error_class',
    'error_message',
    'processed_at',
    'metadata',
])]
class MeetingAnalysis extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MeetingAnalysisStatus::class,
            'result_json' => 'array',
            'metadata' => 'array',
            'processed_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
