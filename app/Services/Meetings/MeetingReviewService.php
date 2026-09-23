<?php

namespace App\Services\Meetings;

use App\Enums\MeetingLeadershipReviewStatus;
use App\Models\Meeting;
use App\Models\MeetingAnalysis;
use App\Models\Person;
use App\Models\User;
use App\Services\Directory\DirectoryService;
use App\Services\Directory\Exceptions\DirectoryException;
use App\Services\Locale\OwnerLocaleResolver;
use App\Services\Meetings\Exceptions\MeetingException;
use App\Services\Productivity\ProductivitySettingsService;

final class MeetingReviewService
{
    public function __construct(
        private readonly MeetingReviewComposer $composer,
        private readonly ProductivitySettingsService $productivity,
        private readonly OwnerLocaleResolver $locales,
        private readonly DirectoryService $directory,
    ) {}

    public function prepareSubject(User $user, Meeting $meeting): Meeting
    {
        if ($meeting->leadership_review_status === MeetingLeadershipReviewStatus::Skipped) {
            return $meeting;
        }

        if ($meeting->review_subject_person_id !== null) {
            return $meeting;
        }

        $settings = $this->productivity->for($user);

        if ($settings->auto_generate_leadership_review === false) {
            return $meeting;
        }

        $personId = $settings->default_review_person_id;

        if ($personId === null) {
            return $meeting;
        }

        $person = Person::query()->where('user_id', $user->id)->whereKey($personId)->first();

        if ($person === null) {
            return $meeting;
        }

        $meeting->forceFill([
            'review_subject_person_id' => $person->id,
        ])->save();

        return $meeting->fresh() ?? $meeting;
    }

    /**
     * @param  array<string, mixed>  $extraction
     * @return array<string, mixed>
     */
    public function compose(User $user, Meeting $meeting, array $extraction, int $commitmentsCreated = 0): array
    {
        $meeting = $this->prepareSubject($user, $meeting);
        $meeting->loadMissing(['participants', 'reviewSubject']);
        $subject = $meeting->reviewSubject;
        $skipped = $meeting->leadership_review_status === MeetingLeadershipReviewStatus::Skipped;
        $matched = false;
        $subjectPayload = null;

        if ($subject instanceof Person && ! $skipped) {
            $mentioned = is_array($extraction['participants'] ?? null) ? $extraction['participants'] : [];
            $participants = $meeting->participants->map(fn ($participant): array => [
                'person_id' => $participant->person_id,
                'display_name' => $participant->display_name,
            ])->all();
            $matched = $this->composer->subjectIsMatched($subject->display_name, $subject->id, $participants, array_values(array_filter($mentioned, 'is_string')));
            $subjectPayload = ['id' => $subject->id, 'name' => $subject->display_name];
        }

        $participantRows = $meeting->participants->map(fn ($participant): array => [
            'person_id' => $participant->person_id,
            'display_name' => $participant->display_name,
        ])->all();
        $composed = $this->composer->compose(
            $extraction,
            $subjectPayload,
            $matched,
            ! $skipped && $subjectPayload !== null,
            $this->locales->assistantLocale($user)->value,
            $meeting->participants->whereNull('person_id')->count(),
            $commitmentsCreated,
            $participantRows,
        );

        $status = MeetingLeadershipReviewStatus::tryFrom((string) ($composed['review']['leadership_status'] ?? '')) ?? MeetingLeadershipReviewStatus::Skipped;
        $meeting->forceFill(['leadership_review_status' => $status])->save();

        return $composed;
    }

    public function recompose(User $user, Meeting $meeting, int $commitmentsCreated = 0): ?MeetingAnalysis
    {
        $analysis = $meeting->currentAnalysis ?? $meeting->analyses()->orderByDesc('version')->first();

        if (! $analysis instanceof MeetingAnalysis || ! is_array($analysis->result_json)) {
            return null;
        }

        $extraction = $analysis->result_json;
        unset($extraction['review']);
        $composed = $this->compose($user, $meeting, $extraction, $commitmentsCreated);
        $analysis->forceFill(['result_json' => $composed])->save();

        return $analysis->fresh() ?? $analysis;
    }

    public function assignSubject(User $user, Meeting $meeting, ?int $personId, bool $skip = false): Meeting
    {
        if ((int) $meeting->user_id !== (int) $user->id) {
            throw new MeetingException('not_found', 'Meeting not found.');
        }

        if ($skip || $personId === null) {
            $meeting->forceFill([
                'review_subject_person_id' => null,
                'leadership_review_status' => MeetingLeadershipReviewStatus::Skipped,
            ])->save();
            $this->recompose($user, $meeting->fresh() ?? $meeting);

            return $meeting->fresh() ?? $meeting;
        }

        try {
            $person = $this->directory->ownedPerson($user, $personId);
        } catch (DirectoryException $exception) {
            throw new MeetingException('not_found', $exception->getMessage());
        }

        $meeting->forceFill([
            'review_subject_person_id' => $person->id,
            'leadership_review_status' => null,
        ])->save();
        $this->recompose($user, $meeting->fresh() ?? $meeting);

        return $meeting->fresh(['reviewSubject']) ?? $meeting;
    }
}
