<?php

namespace App\Models;

use App\Enums\ProactiveAuditAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'proactive_proposal_id',
    'user_id',
    'action',
    'outcome',
    'target_canonical_type',
    'target_canonical_id',
    'external_reference',
    'metadata',
])]
class ProactiveProposalAudit extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ProactiveAuditAction::class,
            'metadata' => 'array',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(ProactiveProposal::class, 'proactive_proposal_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
