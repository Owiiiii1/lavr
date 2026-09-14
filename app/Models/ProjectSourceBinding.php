<?php

namespace App\Models;

use App\Enums\ProjectSourceType;
use App\Enums\SourceBindingKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'source_type',
    'source_id',
    'binding_kind',
    'purpose',
    'importance',
    'monitoring_policy',
    'metadata',
])]
class ProjectSourceBinding extends Model
{
    protected $attributes = [
        'binding_kind' => 'explicit',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => ProjectSourceType::class,
            'binding_kind' => SourceBindingKind::class,
            'metadata' => 'array',
        ];
    }

    public function isExplicit(): bool
    {
        return $this->binding_kind === SourceBindingKind::Explicit;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
