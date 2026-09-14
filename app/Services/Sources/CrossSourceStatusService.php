<?php

namespace App\Services\Sources;

use App\Enums\SourceItemType;
use App\Models\Person;
use App\Models\Project;
use App\Models\SourceItem;
use App\Models\User;
use App\Services\Commitments\CommitmentService;
use App\Services\Meetings\MeetingService;
use Illuminate\Support\Collection;

final class CrossSourceStatusService
{
    public function __construct(
        private readonly CommitmentService $commitments,
        private readonly MeetingService $meetings,
        private readonly ProjectSourceBindingService $bindings,
        private readonly IntegrationAccountResolver $accounts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function person(User $user, Person $person): array
    {
        $items = SourceItem::query()
            ->where('user_id', $user->id)
            ->where('person_id', $person->id)
            ->orderByDesc('occurred_at')
            ->limit(8)
            ->get();

        return [
            'email_facts' => $this->facts($items, SourceItemType::GmailMessage),
            'telegram_facts' => $this->facts($items, SourceItemType::TelegramMessage),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function project(User $user, Project $project): array
    {
        $items = SourceItem::query()
            ->where('user_id', $user->id)
            ->where('project_id', $project->id)
            ->orderByDesc('occurred_at')
            ->limit(12)
            ->get();

        $mailboxHealth = $this->accounts->forProject($user, $project->id, 'gmail')
            ->map(fn ($account): array => [
                'id' => $account->id,
                'label' => $account->label(),
                'health' => $account->health?->value,
            ])
            ->values()
            ->all();

        return [
            'sources' => $this->bindings->serializeForProject($user, $project),
            'mailbox_updates' => $this->facts($items, SourceItemType::GmailMessage),
            'telegram_updates' => $this->facts($items, SourceItemType::TelegramMessage),
            'gmail_health' => $mailboxHealth,
        ];
    }

    /**
     * @param  Collection<int, SourceItem>  $items
     * @return list<array<string, mixed>>
     */
    private function facts($items, SourceItemType $type): array
    {
        return $items
            ->filter(fn (SourceItem $item): bool => $item->source_type === $type)
            ->take(5)
            ->map(fn (SourceItem $item): array => [
                'id' => $item->id,
                'subject' => $item->subject,
                'snippet' => $item->snippet,
                'occurred_at' => optional($item->occurred_at)?->toIso8601String(),
                'source_instance' => $item->source_instance,
            ])
            ->values()
            ->all();
    }
}
