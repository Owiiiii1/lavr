<?php

namespace App\Http\Controllers\Settings;

use App\Enums\MemoryStatus;
use App\Http\Controllers\Controller;
use App\Models\ConversationSummary;
use App\Models\Memory;
use App\Models\MemoryRevision;
use App\Models\Topic;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * @deprecated LAVR is a single-client instance. Third-party user memory admin
 * was removed from the product surface in Phase 1.
 */
class UserMemoryController extends Controller
{
    public function show(User $user): Response
    {
        $memories = Memory::query()
            ->where('user_id', $user->id)
            ->with(['sources' => static fn ($query) => $query->limit(10)])
            ->orderByDesc('last_confirmed_at')
            ->limit(100)
            ->get();

        $memoryIds = $memories->pluck('id');

        $revisions = MemoryRevision::query()
            ->whereIn('memory_id', $memoryIds)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->groupBy('memory_id');

        return Inertia::render('Settings/UserMemory', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
            ],
            'profile' => $user->profile?->summary,
            'topics' => Topic::query()
                ->where('user_id', $user->id)
                ->orderBy('name')
                ->limit(100)
                ->get(['id', 'name', 'normalized_name', 'status', 'first_seen_at', 'last_seen_at'])
                ->all(),
            'memories' => $memories->map(static fn (Memory $memory): array => [
                'id' => $memory->id,
                'kind' => $memory->kind->value,
                'content' => $memory->content,
                'normalized_key' => $memory->normalized_key,
                'confidence' => $memory->confidence,
                'status' => $memory->status->value,
                'valid_from' => optional($memory->valid_from)?->toIso8601String(),
                'valid_until' => optional($memory->valid_until)?->toIso8601String(),
                'last_confirmed_at' => optional($memory->last_confirmed_at)?->toIso8601String(),
                'sources' => $memory->sources->map(static fn ($source): array => [
                    'source_kind' => $source->source_kind->value,
                    'message_id' => $source->message_id,
                    'conversation_id' => $source->conversation_id,
                ])->all(),
                'revisions' => ($revisions[$memory->id] ?? collect())->map(static fn (MemoryRevision $revision): array => [
                    'id' => $revision->id,
                    'previous_content' => $revision->previous_content,
                    'new_content' => $revision->new_content,
                    'previous_status' => $revision->previous_status,
                    'new_status' => $revision->new_status,
                    'reason' => $revision->reason,
                    'created_at' => optional($revision->created_at)?->toIso8601String(),
                ])->all(),
            ])->all(),
            'summaries' => ConversationSummary::query()
                ->where('user_id', $user->id)
                ->with('conversation:id,title')
                ->orderByDesc('generated_at')
                ->limit(50)
                ->get()
                ->map(static fn (ConversationSummary $summary): array => [
                    'id' => $summary->id,
                    'conversation_id' => $summary->conversation_id,
                    'conversation_title' => $summary->conversation?->title,
                    'summary' => $summary->summary,
                    'version' => $summary->version,
                    'status' => $summary->status->value,
                    'message_count' => $summary->message_count,
                    'generated_at' => optional($summary->generated_at)?->toIso8601String(),
                ])
                ->all(),
            'active_count' => Memory::query()
                ->where('user_id', $user->id)
                ->where('status', MemoryStatus::Active)
                ->count(),
        ]);
    }
}
