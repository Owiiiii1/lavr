<?php

namespace App\Models;

use App\Enums\ProjectSourceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'source_type',
    'source_id',
    'purpose',
    'importance',
    'monitoring_policy',
    'metadata',
])]
class ProjectSourceBinding extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => ProjectSourceType::class,
            'metadata' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
