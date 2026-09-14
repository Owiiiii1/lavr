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
    'operational_alerts_enabled',
    'operational_min_severity',
    'operational_max_alerts_per_day',
    'quiet_hours_start',
    'quiet_hours_end',
    'critical_bypass_quiet_hours',
    'auto_create_reminders',
    'auto_draft_messages',
    'third_party_execute',
    'disabled_operational_rules',
    'operational_rule_prefs',
    'morning_brief_enabled',
    'morning_brief_local_time',
    'morning_brief_telegram',
    'morning_brief_inbox',
    'morning_brief_weekends',
    'leadership_review_enabled',
    'leadership_review_weekday',
    'leadership_review_local_time',
    'leadership_review_telegram',
    'leadership_review_inbox',
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
            'operational_alerts_enabled' => 'boolean',
            'operational_max_alerts_per_day' => 'integer',
            'critical_bypass_quiet_hours' => 'boolean',
            'auto_create_reminders' => 'boolean',
            'auto_draft_messages' => 'boolean',
            'third_party_execute' => 'boolean',
            'disabled_operational_rules' => 'array',
            'operational_rule_prefs' => 'array',
            'morning_brief_enabled' => 'boolean',
            'morning_brief_telegram' => 'boolean',
            'morning_brief_inbox' => 'boolean',
            'morning_brief_weekends' => 'boolean',
            'leadership_review_enabled' => 'boolean',
            'leadership_review_telegram' => 'boolean',
            'leadership_review_inbox' => 'boolean',
            'leadership_review_weekday' => 'integer',
            'weekly_review_weekday' => 'integer',
            'last_daily_brief_at' => 'immutable_datetime',
            'last_evening_review_at' => 'immutable_datetime',
            'last_weekly_review_at' => 'immutable_datetime',
            'last_morning_brief_at' => 'immutable_datetime',
            'last_leadership_review_at' => 'immutable_datetime',
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
        'operational_alerts_enabled' => true,
        'operational_min_severity' => 'high',
        'operational_max_alerts_per_day' => 6,
        'critical_bypass_quiet_hours' => true,
        'auto_create_reminders' => false,
        'auto_draft_messages' => false,
        'third_party_execute' => false,
        'morning_brief_enabled' => true,
        'morning_brief_local_time' => '08:30',
        'morning_brief_telegram' => true,
        'morning_brief_inbox' => true,
        'morning_brief_weekends' => false,
        'leadership_review_enabled' => true,
        'leadership_review_weekday' => 1,
        'leadership_review_local_time' => '09:00',
        'leadership_review_telegram' => true,
        'leadership_review_inbox' => true,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
