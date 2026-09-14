<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'validation_batch_id',
    'entity_type',
    'entity_id',
])]
class ValidationBatchItem extends Model
{
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ValidationBatch::class, 'validation_batch_id');
    }
}
