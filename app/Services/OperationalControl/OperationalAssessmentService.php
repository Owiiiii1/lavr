<?php

namespace App\Services\OperationalControl;

use App\Enums\OperationalActionability;
use App\Enums\OperationalEventStatus;
use App\Enums\OperationalEventType;
use App\Enums\OperationalSeverity;
use App\Models\OperationalEvent;
use App\Models\User;
use App\Services\Productivity\ProductivitySettingsService;

final class OperationalAssessmentService
{
    public function __construct(
        private readonly ProductivitySettingsService $settings,
    ) {}

    public function assess(User $user, OperationalEvent $event, OperationalRuleMatch $match): OperationalAssessment
    {
        $severity = $event->severity instanceof OperationalSeverity ? $event->severity : $match->severity;
        $important = $severity->atLeast(OperationalSeverity::High);
        $urgent = $severity->atLeast(OperationalSeverity::High);
        $actionable = $match->actionability !== OperationalActionability::Informational;
        $notify = $this->shouldNotify($user, $severity);

        if ($event->status === OperationalEventStatus::Dismissed
            || $event->status === OperationalEventStatus::Resolved
            || $event->status === OperationalEventStatus::Superseded) {
            $notify = false;
            $actionable = false;
        }

        return new OperationalAssessment(
            severity: $severity,
            important: $important,
            urgent: $urgent,
            actionable: $actionable,
            notify: $notify,
            actionability: $match->actionability,
            recommendedNextStep: $match->recommendedAction,
            context: [
                'event_type' => $event->event_type instanceof OperationalEventType ? $event->event_type->value : (string) $event->event_type,
                'person_id' => $event->person_id,
                'project_id' => $event->project_id,
                'commitment_id' => $event->commitment_id,
                'meeting_id' => $event->meeting_id,
            ],
        );
    }

    public function apply(OperationalEvent $event, OperationalAssessment $assessment): OperationalEvent
    {
        if ($event->status === OperationalEventStatus::Dismissed
            || $event->status === OperationalEventStatus::Resolved
            || $event->status === OperationalEventStatus::Superseded) {
            return $event;
        }

        $event->forceFill([
            'status' => $assessment->actionable ? OperationalEventStatus::Actionable : OperationalEventStatus::Assessed,
            'severity' => $assessment->severity,
        ])->save();

        return $event->fresh() ?? $event;
    }

    private function shouldNotify(User $user, OperationalSeverity $severity): bool
    {
        $settings = $this->settings->for($user);
        if (($settings->operational_alerts_enabled ?? true) !== true) {
            return false;
        }

        $minimum = OperationalSeverity::tryFrom((string) ($settings->operational_min_severity ?: config('operational_control.default_min_severity', 'high')))
            ?? OperationalSeverity::High;

        return $severity->atLeast($minimum);
    }
}
