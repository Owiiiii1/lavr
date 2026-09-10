<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'daily_brief_enabled',
    'daily_brief_local_time',
    'evening_review_enabled',
    'evening_review_local_time',
    'weekly_review_enabled',
    'weekly_review_weekday',
    'weekly_review_local_time',
    'proactive_enabled',
    'morning_brief_enabled',
    'morning_brief_local_time',
    'morning_brief_telegram',
    'morning_brief_inbox',
    'morning_brief_weekends',
    'last_daily_brief_at',
    'last_evening_review_at',
    'last_weekly_review_at',
    'last_morning_brief_at',
    'metadata',
])]
class UserProductivitySetting extends Model
{
    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'daily_brief_enabled' => 'boolean',
            'evening_review_enabled' => 'boolean',
            'weekly_review_enabled' => 'boolean',
            'proactive_enabled' => 'boolean',
            'morning_brief_enabled' => 'boolean',
            'morning_brief_telegram' => 'boolean',
            'morning_brief_inbox' => 'boolean',
            'morning_brief_weekends' => 'boolean',
            'weekly_review_weekday' => 'integer',
            'last_daily_brief_at' => 'immutable_datetime',
            'last_evening_review_at' => 'immutable_datetime',
            'last_weekly_review_at' => 'immutable_datetime',
            'last_morning_brief_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'daily_brief_enabled' => false,
        'daily_brief_local_time' => '08:00',
        'evening_review_enabled' => false,
        'evening_review_local_time' => '20:00',
        'weekly_review_enabled' => false,
        'weekly_review_weekday' => 7,
        'weekly_review_local_time' => '18:00',
        'proactive_enabled' => false,
        'morning_brief_enabled' => true,
        'morning_brief_local_time' => '08:30',
        'morning_brief_telegram' => true,
        'morning_brief_inbox' => true,
        'morning_brief_weekends' => false,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
