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
            'morning_brief_enabled' => ['required', 'boolean'],
            'morning_brief_local_time' => ['required', 'date_format:H:i'],
            'morning_brief_telegram' => ['required', 'boolean'],
            'morning_brief_inbox' => ['required', 'boolean'],
            'morning_brief_weekends' => ['required', 'boolean'],
        ]);

        $this->settings->update($user, $validated);

        return back()->with('success', 'Productivity settings saved.');
    }
}
