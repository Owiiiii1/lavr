<?php

namespace App\Services\Sources;

use App\Enums\PersonIdentityType;
use App\Models\Person;
use App\Models\PersonIdentity;
use App\Models\User;
use App\Services\Directory\IdentityNormalizer;

final class SourceIdentityResolver
{
    /**
     * @return array{person_id: ?int, identity_id: ?int, value: string, type: string, resolved: bool, unresolved: bool}
     */
    public function resolveEmail(User $user, string $email): array
    {
        return $this->resolve($user, PersonIdentityType::Email, $email);
    }

    /**
     * @return array{person_id: ?int, identity_id: ?int, value: string, type: string, resolved: bool, unresolved: bool}
     */
    public function resolveTelegramUserId(User $user, string $telegramUserId): array
    {
        return $this->resolve($user, PersonIdentityType::TelegramUserId, $telegramUserId);
    }

    /**
     * @return array{person_id: ?int, identity_id: ?int, value: string, type: string, resolved: bool, unresolved: bool}
     */
    public function resolveTelegramUsername(User $user, string $username): array
    {
        return $this->resolve($user, PersonIdentityType::TelegramUsername, $username);
    }

    /**
     * @return array{person_id: ?int, identity_id: ?int, value: string, type: string, resolved: bool, unresolved: bool}
     */
    public function resolve(User $user, PersonIdentityType $type, string $value): array
    {
        $normalized = IdentityNormalizer::normalize($type, $value);
        if ($normalized === '') {
            return [
                'person_id' => null,
                'identity_id' => null,
                'value' => '',
                'type' => $type->value,
                'resolved' => false,
                'unresolved' => false,
            ];
        }

        $identity = PersonIdentity::query()
            ->where('type', $type->value)
            ->where('normalized_value', $normalized)
            ->whereHas('person', fn ($query) => $query->where('user_id', $user->id))
            ->first();

        if ($identity === null && $type === PersonIdentityType::Email) {
            $person = Person::query()
                ->where('user_id', $user->id)
                ->where('primary_email', $normalized)
                ->first();

            if ($person !== null) {
                return [
                    'person_id' => $person->id,
                    'identity_id' => null,
                    'value' => $normalized,
                    'type' => $type->value,
                    'resolved' => true,
                    'unresolved' => false,
                ];
            }
        }

        if ($identity === null) {
            return [
                'person_id' => null,
                'identity_id' => null,
                'value' => $normalized,
                'type' => $type->value,
                'resolved' => false,
                'unresolved' => true,
            ];
        }

        return [
            'person_id' => (int) $identity->person_id,
            'identity_id' => $identity->id,
            'value' => $normalized,
            'type' => $type->value,
            'resolved' => true,
            'unresolved' => false,
        ];
    }
}
