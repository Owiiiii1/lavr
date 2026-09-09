<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'meeting_id',
    'person_id',
    'display_name',
    'email',
    'role',
    'attendance_status',
    'speaker_key',
    'source_identifier',
    'metadata',
])]
class MeetingParticipant extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function isResolved(): bool
    {
        return $this->person_id !== null;
    }
}
