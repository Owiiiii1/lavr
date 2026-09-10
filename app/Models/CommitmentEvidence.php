<?php

namespace App\Models;

use App\Enums\CommitmentConfidence;
use App\Enums\CommitmentEvidenceType;
use App\Enums\CommitmentSourceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'commitment_id',
    'evidence_type',
    'source_type',
    'source_id',
    'excerpt',
    'observed_at',
    'confidence',
    'metadata',
])]
class CommitmentEvidence extends Model
{
    protected $table = 'commitment_evidence';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'evidence_type' => CommitmentEvidenceType::class,
            'source_type' => CommitmentSourceType::class,
            'observed_at' => 'datetime',
            'confidence' => CommitmentConfidence::class,
            'metadata' => 'array',
        ];
    }

    public function commitment(): BelongsTo
    {
        return $this->belongsTo(Commitment::class);
    }
}
