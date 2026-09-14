<?php

namespace App\Services\Sources;

use App\Enums\CommitmentLifecycleStatus;
use App\Models\Commitment;
use App\Models\Message;
use App\Models\SourceItem;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Services\Directory\Exceptions\DirectoryException;
use Carbon\CarbonImmutable;

final class TelegramGroupSummaryService
{
    /**
     * @return array<string, mixed>
     */
    public function summarize(User $user, TelegramGroup $group, ?CarbonImmutable $day = null): array
    {
        $this->assertVisible($user, $group);
        $day ??= CarbonImmutable::now($user->timezone ?: 'UTC');
        $start = $day->startOfDay();
        $end = $day->endOfDay();

        $messages = Message::query()
            ->where('telegram_group_id', $group->id)
            ->whereBetween('occurred_at', [$start, $end])
            ->orderBy('occurred_at')
            ->limit(80)
            ->get();

        $items = SourceItem::query()
            ->where('user_id', $user->id)
            ->where('source_instance', 'telegram_group:'.$group->id)
            ->whereBetween('occurred_at', [$start, $end])
            ->get();

        $itemIds = $items->pluck('id')->all();
        $projectIds = $group->projects()->pluck('projects.id')->all();
        $commitments = Commitment::query()
            ->where('user_id', $user->id)
            ->whereIn('lifecycle_status', [
                CommitmentLifecycleStatus::Detected->value,
                CommitmentLifecycleStatus::Open->value,
                CommitmentLifecycleStatus::LikelyDone->value,
            ])
            ->where(function ($query) use ($itemIds, $projectIds): void {
                if ($itemIds === [] && $projectIds === []) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                if ($itemIds !== []) {
                    $query->orWhereIn('source_id', $itemIds);
                }
                if ($projectIds !== []) {
                    $query->orWhereIn('project_id', $projectIds);
                }
            })
            ->get(['id', 'title', 'lifecycle_status']);

        $updates = [];
        $questions = [];
        foreach ($messages as $message) {
            $body = trim((string) $message->body);
            if ($body === '') {
                continue;
            }
            if (str_contains($body, '?')) {
                $questions[] = mb_substr($body, 0, 180);
            } else {
                $updates[] = mb_substr($body, 0, 180);
            }
        }

        return [
            'group_id' => $group->id,
            'title' => $group->title,
            'day' => $start->toDateString(),
            'message_count' => $messages->count(),
            'updates' => array_slice($updates, 0, 8),
            'commitments' => $commitments->map(fn (Commitment $commitment): array => [
                'id' => $commitment->id,
                'title' => $commitment->title,
                'status' => $commitment->lifecycle_status?->value,
            ])->all(),
            'blockers' => [],
            'decisions' => [],
            'unanswered_questions' => array_slice($questions, 0, 6),
            'health' => $group->status->value,
            'last_message_at' => optional($group->last_message_at)?->toIso8601String(),
            'monitoring_enabled' => (bool) ((is_array($group->settings) ? $group->settings : [])['monitoring_enabled'] ?? false),
        ];
    }

    private function assertVisible(User $user, TelegramGroup $group): void
    {
        $conversation = $group->conversation;
        if ($conversation === null || (int) $conversation->user_id !== (int) $user->id) {
            throw new DirectoryException('not_found');
        }
    }
}
