<?php

namespace App\Models;

use App\Enums\ZoomWebhookEventStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'external_event_key',
    'event_name',
    'account_id',
    'meeting_uuid',
    'meeting_numeric_id',
    'recording_file_id',
    'status',
    'meeting_id',
    'attempts',
    'error_class',
    'error_message',
    'payload_subset',
    'received_at',
    'processed_at',
])]
class ZoomWebhookEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ZoomWebhookEventStatus::class,
            'payload_subset' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
