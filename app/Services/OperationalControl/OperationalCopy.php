<?php

namespace App\Services\OperationalControl;

use App\Enums\OwnerLocale;
use App\Enums\ProactiveProposalType;
use App\Models\ProactiveProposal;
use App\Models\User;
use App\Services\Locale\OwnerLocaleResolver;

final class OperationalCopy
{
    public function __construct(
        private readonly OwnerLocaleResolver $locales,
    ) {}

    public function alertBody(User $user, ProactiveProposal $proposal): string
    {
        $locale = $this->locales->interfaceLocale($user);

        return $proposal->rationale.' '.$this->cta($locale, $proposal);
    }

    public function telegramAlert(User $user, ProactiveProposal $proposal): string
    {
        return $proposal->title."\n".$this->alertBody($user, $proposal);
    }

    private function cta(OwnerLocale $locale, ProactiveProposal $proposal): string
    {
        $type = $proposal->proposal_type instanceof ProactiveProposalType
            ? $proposal->proposal_type
            : ProactiveProposalType::OpenSource;

        return match ($type) {
            ProactiveProposalType::RemindPerson => match ($locale) {
                OwnerLocale::En => 'Remind them? LAVR will not write unless you allow it. Open: /lavr/proactive/'.$proposal->id,
                OwnerLocale::Ru => 'Напомнить? LAVR не напишет без вашего разрешения. Открыть: /lavr/proactive/'.$proposal->id,
                default => 'Нагадати? LAVR не напише без вашого дозволу. Відкрити: /lavr/proactive/'.$proposal->id,
            },
            ProactiveProposalType::ConfirmCommitment => match ($locale) {
                OwnerLocale::En => 'Confirm completion? Open: /lavr/proactive/'.$proposal->id,
                OwnerLocale::Ru => 'Подтвердить выполнение? Открыть: /lavr/proactive/'.$proposal->id,
                default => 'Підтвердити виконання? Відкрити: /lavr/proactive/'.$proposal->id,
            },
            ProactiveProposalType::ReconnectIntegration => match ($locale) {
                OwnerLocale::En => 'Reconnect the source. Open: /lavr/proactive/'.$proposal->id,
                OwnerLocale::Ru => 'Подключите источник снова. Открыть: /lavr/proactive/'.$proposal->id,
                default => 'Підключіть джерело знову. Відкрити: /lavr/proactive/'.$proposal->id,
            },
            default => match ($locale) {
                OwnerLocale::En => 'Open: /lavr/proactive/'.$proposal->id,
                OwnerLocale::Ru => 'Открыть: /lavr/proactive/'.$proposal->id,
                default => 'Відкрити: /lavr/proactive/'.$proposal->id,
            },
        };
    }
}
