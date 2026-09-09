<?php

namespace App\Services\Meetings;

use App\Enums\PersonIdentityType;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\Person;
use App\Models\PersonIdentity;
use App\Models\User;
use App\Services\Directory\IdentityNormalizer;
use App\Services\Projects\ProjectNameNormalizer;

final class ParticipantResolver
{
    /**
     * @param  list<string>  $speakers
     * @param  list<array{display_name?: string, email?: string|null, speaker_key?: string|null}>  $explicit
     * @return list<MeetingParticipant>
     */
    public function seedFromTranscript(User $user, Meeting $meeting, array $speakers, array $explicit = []): array
    {
        $rows = [];

        foreach ($explicit as $item) {
            $name = trim((string) ($item['display_name'] ?? ''));
            $email = $this->nullableEmail($item['email'] ?? null);

            if ($name === '' && $email === null) {
                continue;
            }

            $rows[] = $this->upsertParticipant(
                $user,
                $meeting,
                $name !== '' ? $name : (string) $email,
                $email,
                isset($item['speaker_key']) ? (string) $item['speaker_key'] : null,
            );
        }

        foreach ($speakers as $speaker) {
            $name = trim($speaker);

            if ($name === '') {
                continue;
            }

            $rows[] = $this->upsertParticipant($user, $meeting, $name, null, $name);
        }

        return $rows;
    }

    public function upsertParticipant(
        User $user,
        Meeting $meeting,
        string $displayName,
        ?string $email = null,
        ?string $speakerKey = null,
    ): MeetingParticipant {
        $displayName = trim($displayName);
        $email = $this->nullableEmail($email);
        $speakerKey = $speakerKey !== null && trim($speakerKey) !== '' ? trim($speakerKey) : $displayName;

        $existing = $this->findExisting($meeting, $displayName, $email, $speakerKey);

        if ($existing !== null) {
            if ($email !== null && $existing->email === null) {
                $existing->email = $email;
            }

            if ($existing->person_id === null) {
                $existing->person_id = $this->resolvePersonId($user, $displayName, $email);
            }

            $existing->save();

            return $existing;
        }

        return MeetingParticipant::query()->create([
            'meeting_id' => $meeting->id,
            'person_id' => $this->resolvePersonId($user, $displayName, $email),
            'display_name' => $displayName,
            'email' => $email,
            'speaker_key' => $speakerKey,
            'source_identifier' => $email ?? $speakerKey,
        ]);
    }

    public function resolvePersonId(User $user, string $displayName, ?string $email): ?int
    {
        if ($email !== null) {
            $normalized = IdentityNormalizer::normalize(PersonIdentityType::Email, $email);
            $identity = PersonIdentity::query()
                ->where('type', PersonIdentityType::Email)
                ->where('normalized_value', $normalized)
                ->whereHas('person', fn ($person) => $person->where('user_id', $user->id))
                ->first();

            if ($identity !== null) {
                return (int) $identity->person_id;
            }

            $byPrimary = Person::query()
                ->where('user_id', $user->id)
                ->whereRaw('LOWER(primary_email) = ?', [mb_strtolower($email)])
                ->first();

            if ($byPrimary !== null) {
                return (int) $byPrimary->id;
            }
        }

        $normalizedName = ProjectNameNormalizer::normalize($displayName);

        if ($normalizedName === '') {
            return null;
        }

        $matches = Person::query()
            ->where('user_id', $user->id)
            ->where('normalized_name', $normalizedName)
            ->limit(2)
            ->get();

        if ($matches->count() === 1) {
            return (int) $matches->first()->id;
        }

        return null;
    }

    public function link(MeetingParticipant $participant, Person $person): MeetingParticipant
    {
        $participant->forceFill([
            'person_id' => $person->id,
            'display_name' => $participant->display_name !== '' ? $participant->display_name : $person->display_name,
            'email' => $participant->email ?: $person->primary_email,
        ])->save();

        return $participant;
    }

    public function unlink(MeetingParticipant $participant): MeetingParticipant
    {
        $participant->forceFill(['person_id' => null])->save();

        return $participant;
    }

    private function findExisting(Meeting $meeting, string $displayName, ?string $email, ?string $speakerKey): ?MeetingParticipant
    {
        $query = MeetingParticipant::query()->where('meeting_id', $meeting->id);

        if ($email !== null) {
            $match = (clone $query)->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();

            if ($match !== null) {
                return $match;
            }
        }

        if ($speakerKey !== null && $speakerKey !== '') {
            $match = (clone $query)->where('speaker_key', $speakerKey)->first();

            if ($match !== null) {
                return $match;
            }
        }

        return $query->whereRaw('LOWER(display_name) = ?', [mb_strtolower($displayName)])->first();
    }

    private function nullableEmail(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $email = mb_strtolower(trim($value));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
