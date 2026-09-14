<?php

namespace App\Models;

use App\Enums\IntegrationAccountStatus;
use App\Enums\IntegrationHealth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationAccount extends Model
{
    protected $hidden = [
        'credentials_encrypted',
    ];

    protected $attributes = [
        'enabled' => true,
        'health' => 'disabled',
    ];

    protected $fillable = [
        'user_id',
        'provider',
        'external_account_id',
        'external_account_email',
        'display_label',
        'status',
        'enabled',
        'health',
        'scopes',
        'credentials_encrypted',
        'metadata',
        'connected_at',
        'disconnected_at',
        'last_used_at',
        'last_success_at',
        'last_error_at',
        'last_error_code',
        'last_error_message',
        'last_event_at',
        'last_processed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => IntegrationAccountStatus::class,
            'health' => IntegrationHealth::class,
            'enabled' => 'boolean',
            'scopes' => 'array',
            'credentials_encrypted' => 'encrypted:array',
            'metadata' => 'array',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
            'last_event_at' => 'datetime',
            'last_processed_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        unset($array['credentials_encrypted'], $array['credentials']);

        return $array;
    }

    public function label(): string
    {
        $label = trim((string) $this->display_label);

        return $label !== '' ? $label : (string) ($this->external_account_email ?: $this->provider);
    }

    public function isEnabled(): bool
    {
        return $this->enabled === true;
    }

    public function isProcessable(): bool
    {
        return $this->isEnabled()
            && $this->status === IntegrationAccountStatus::Connected
            && $this->health !== IntegrationHealth::Disabled
            && $this->health !== IntegrationHealth::Blocked;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function executionLogs(): HasMany
    {
        return $this->hasMany(ToolExecutionLog::class);
    }

    public function sourceItems(): HasMany
    {
        return $this->hasMany(SourceItem::class);
    }
}
