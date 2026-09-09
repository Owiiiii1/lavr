<?php

namespace App\Models;

use App\Enums\MeetingArtifactKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'meeting_id',
    'kind',
    'original_filename',
    'mime_type',
    'extension',
    'checksum_sha256',
    'byte_size',
    'disk',
    'storage_path',
    'original_text',
    'normalized_text',
    'source_language',
    'metadata',
])]
class MeetingArtifact extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MeetingArtifactKind::class,
            'metadata' => 'array',
            'byte_size' => 'integer',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
