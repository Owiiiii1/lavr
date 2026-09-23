<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Services\Productivity\ProductivitySettingsService;
use App\Services\Users\UserCapability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class JarvisProductivitySettingsController extends Controller
{
    public function __construct(
        private readonly ProductivitySettingsService $settings,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->isActive() || ! $user->canUseCapability(UserCapability::TASKS)) {
            abort(403);
        }

        $validated = $request->validate([
            'daily_brief_enabled' => ['required', 'boolean'],
            'daily_brief_local_time' => ['required', 'date_format:H:i'],
            'evening_review_enabled' => ['required', 'boolean'],
            'evening_review_local_time' => ['required', 'date_format:H:i'],
            'weekly_review_enabled' => ['required', 'boolean'],
            'weekly_review_weekday' => ['required', 'integer', 'between:1,7'],
            'weekly_review_local_time' => ['required', 'date_format:H:i'],
            'proactive_enabled' => ['required', 'boolean'],
            'operational_alerts_enabled' => ['sometimes', 'boolean'],
            'operational_min_severity' => ['sometimes', 'string', 'in:critical,high,normal,low'],
            'operational_max_alerts_per_day' => ['sometimes', 'integer', 'between:1,20'],
            'quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i'],
            'critical_bypass_quiet_hours' => ['sometimes', 'boolean'],
            'auto_create_reminders' => ['sometimes', 'boolean'],
            'auto_draft_messages' => ['sometimes', 'boolean'],
            'third_party_execute' => ['sometimes', 'boolean'],
            'disabled_operational_rules' => ['sometimes', 'array'],
            'disabled_operational_rules.*' => ['string', 'max:64'],
            'morning_brief_enabled' => ['required', 'boolean'],
            'morning_brief_local_time' => ['required', 'date_format:H:i'],
            'morning_brief_telegram' => ['required', 'boolean'],
            'morning_brief_inbox' => ['required', 'boolean'],
            'morning_brief_weekends' => ['required', 'boolean'],
            'leadership_review_enabled' => ['required', 'boolean'],
            'leadership_review_weekday' => ['required', 'integer', 'between:1,7'],
            'leadership_review_local_time' => ['required', 'date_format:H:i'],
            'leadership_review_telegram' => ['required', 'boolean'],
            'leadership_review_inbox' => ['required', 'boolean'],
            'auto_generate_leadership_review' => ['sometimes', 'boolean'],
            'default_review_person_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $this->settings->update($user, $validated);

        return back()->with('success', 'Productivity settings saved.');
    }
}
