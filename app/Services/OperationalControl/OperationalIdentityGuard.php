<?php

namespace App\Services\OperationalControl;

use App\Enums\PersonIdentityType;
use App\Models\Person;
use App\Models\PersonIdentity;
use App\Models\ProactiveProposal;

final class OperationalIdentityGuard
{
    /**
     * @return array{ok: bool, reason: ?string, telegram_user_id: ?string, email: ?string}
     */
    public function resolve(ProactiveProposal $proposal): array
    {
        if ($proposal->person_id === null) {
            return ['ok' => false, 'reason' => 'unresolved_identity', 'telegram_user_id' => null, 'email' => null];
        }

        $person = Person::query()->with('identities')->find($proposal->person_id);
        if ($person === null) {
            return ['ok' => false, 'reason' => 'unresolved_identity', 'telegram_user_id' => null, 'email' => null];
        }

        $telegram = $person->identities
            ->filter(fn (PersonIdentity $identity): bool => $identity->type === PersonIdentityType::TelegramUserId)
            ->values();
        $emails = $person->identities
            ->filter(fn (PersonIdentity $identity): bool => in_array($identity->type, [PersonIdentityType::Email, PersonIdentityType::ZoomEmail], true))
            ->values();

        if ($telegram->count() > 1) {
            return ['ok' => false, 'reason' => 'ambiguous_identity', 'telegram_user_id' => null, 'email' => null];
        }

        $telegramId = $telegram->count() === 1 ? (string) $telegram->first()->normalized_value : null;
        $email = $emails->count() === 1 ? (string) $emails->first()->normalized_value : ($person->primary_email ?: null);

        if ($telegramId === null && ($email === null || $email === '')) {
            return ['ok' => false, 'reason' => 'unresolved_identity', 'telegram_user_id' => null, 'email' => null];
        }

        return [
            'ok' => true,
            'reason' => null,
            'telegram_user_id' => $telegramId,
            'email' => $email,
        ];
    }
}
