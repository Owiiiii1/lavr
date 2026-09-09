<?php

namespace App\Http\Controllers;

use App\Enums\ConversationKind;
use App\Enums\MemoryStatus;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Project;
use App\Models\TelegramGroup;
use App\Models\Topic;
use App\Services\Directory\DirectoryService;
use App\Services\Directory\Exceptions\DirectoryException;
use App\Services\Projects\Exceptions\ProjectException;
use App\Services\Projects\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly DirectoryService $directory,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $user = $request->user();

        return Inertia::render('Projects/Index', [
            'projects' => $this->projects->listForOwner($user)->map(static fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status->value,
                'conversations_count' => (int) $project->conversations_count,
                'topics_count' => (int) $project->topics_count,
                'memories_count' => (int) $project->memories_count,
                'groups_count' => (int) $project->telegram_groups_count,
                'people_count' => (int) $project->people_count,
                'organizations_count' => (int) $project->organizations_count,
                'category' => $project->category,
                'updated_at' => optional($project->updated_at)?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:'.(int) config('projects.description_max')],
        ]);

        try {
            $project = $this->projects->create(
                $request->user(),
                $validated['name'],
                $validated['description'] ?? null,
            );
        } catch (ProjectException $exception) {
            return back()->withErrors(['name' => $this->messageFor($exception)]);
        }

        return redirect()->route('projects.show', $project);
    }

    public function show(Request $request, Project $project): Response
    {
        $this->authorizeOwned($request, $project, 'view');

        $user = $request->user();
        $project->load([
            'conversations:id,user_id,title,last_activity_at',
            'topics:id,user_id,name,status',
            'memories:id,user_id,content,kind,confidence,status',
            'telegramGroups:id,title,chat_type,status',
            'people:id,display_name,status',
            'organizations:id,name,status',
            'ownerPerson:id,display_name',
        ]);

        $attachedConversationIds = $project->conversations->pluck('id');
        $attachedTopicIds = $project->topics->pluck('id');
        $attachedMemoryIds = $project->memories->pluck('id');
        $attachedGroupIds = $project->telegramGroups->pluck('id');

        return Inertia::render('Projects/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status->value,
                'category' => $project->category,
                'start_date' => optional($project->start_date)?->toDateString(),
                'end_date' => optional($project->end_date)?->toDateString(),
                'owner_person_id' => $project->owner_person_id,
                'updated_at' => optional($project->updated_at)?->toIso8601String(),
                'conversations' => $project->conversations->map(static fn (Conversation $conversation): array => [
                    'id' => $conversation->id,
                    'title' => $conversation->title,
                    'last_activity_at' => optional($conversation->last_activity_at)?->toIso8601String(),
                ])->all(),
                'topics' => $project->topics->map(static fn (Topic $topic): array => [
                    'id' => $topic->id,
                    'name' => $topic->name,
                    'status' => $topic->status->value,
                ])->all(),
                'memories' => $project->memories->map(static fn (Memory $memory): array => [
                    'id' => $memory->id,
                    'content' => $memory->content,
                    'kind' => $memory->kind->value,
                    'confidence' => $memory->confidence,
                    'status' => $memory->status->value,
                ])->all(),
                'groups' => $project->telegramGroups->map(static fn (TelegramGroup $group): array => [
                    'id' => $group->id,
                    'title' => $group->title ?: 'Untitled group',
                    'chat_type' => $group->chat_type,
                    'status' => $group->status->value,
                ])->all(),
                'people' => $project->people->map(static fn (Person $person): array => [
                    'id' => $person->id,
                    'display_name' => $person->display_name,
                    'role' => $person->pivot->role ?? null,
                ])->all(),
                'organizations' => $project->organizations->map(static fn (Organization $organization): array => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'role' => $organization->pivot->role ?? null,
                ])->all(),
            ],
            'availableConversations' => Conversation::query()
                ->where('user_id', $user->id)
                ->where('kind', ConversationKind::Personal)
                ->whereNotIn('id', $attachedConversationIds)
                ->orderByRaw('last_activity_at IS NULL')
                ->orderByDesc('last_activity_at')
                ->limit(50)
                ->get(['id', 'title', 'last_activity_at'])
                ->map(static fn (Conversation $conversation): array => [
                    'id' => $conversation->id,
                    'title' => $conversation->title,
                    'last_activity_at' => optional($conversation->last_activity_at)?->toIso8601String(),
                ])
                ->all(),
            'availableTopics' => Topic::query()
                ->where('user_id', $user->id)
                ->whereNotIn('id', $attachedTopicIds)
                ->orderBy('name')
                ->limit(50)
                ->get(['id', 'name', 'status'])
                ->map(static fn (Topic $topic): array => [
                    'id' => $topic->id,
                    'name' => $topic->name,
                    'status' => $topic->status->value,
                ])
                ->all(),
            'availableMemories' => Memory::query()
                ->where('user_id', $user->id)
                ->where('status', MemoryStatus::Active)
                ->whereNotIn('id', $attachedMemoryIds)
                ->orderByDesc('confidence')
                ->limit(50)
                ->get(['id', 'content', 'kind', 'confidence'])
                ->map(static fn (Memory $memory): array => [
                    'id' => $memory->id,
                    'content' => $memory->content,
                    'kind' => $memory->kind->value,
                    'confidence' => $memory->confidence,
                ])
                ->all(),
            'availableGroups' => TelegramGroup::query()
                ->active()
                ->whereNotIn('id', $attachedGroupIds)
                ->orderBy('title')
                ->limit(50)
                ->get(['id', 'title', 'chat_type', 'status'])
                ->map(static fn (TelegramGroup $group): array => [
                    'id' => $group->id,
                    'title' => $group->title ?: 'Untitled group',
                    'chat_type' => $group->chat_type,
                    'status' => $group->status->value,
                ])
                ->all(),
            'availablePeople' => Person::query()
                ->where('user_id', $user->id)
                ->whereNotIn('id', $project->people->pluck('id'))
                ->orderBy('display_name')
                ->limit(50)
                ->get(['id', 'display_name'])
                ->map(static fn (Person $person): array => [
                    'id' => $person->id,
                    'display_name' => $person->display_name,
                ])
                ->all(),
            'availableOrganizations' => Organization::query()
                ->where('user_id', $user->id)
                ->whereNotIn('id', $project->organizations->pluck('id'))
                ->orderBy('name')
                ->limit(50)
                ->get(['id', 'name'])
                ->map(static fn (Organization $organization): array => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                ])
                ->all(),
            'descriptionMax' => (int) config('projects.description_max'),
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'update');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:'.(int) config('projects.description_max')],
            'category' => ['nullable', 'string', 'max:80'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:32'],
            'owner_person_id' => ['nullable', 'integer'],
        ]);

        try {
            $this->projects->update(
                $request->user(),
                $project,
                $validated['name'],
                $validated['description'] ?? null,
            );
            $this->projects->updateContext($request->user(), $project, $validated);
        } catch (ProjectException $exception) {
            return back()->withErrors(['name' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function archive(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'archive');
        $this->projects->archive($request->user(), $project);

        return back();
    }

    public function restore(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'restore');
        $this->projects->restore($request->user(), $project);

        return back();
    }

    public function attachConversation(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'attach');
        $validated = $request->validate(['conversation_id' => ['required', 'integer']]);
        $conversation = Conversation::query()->findOrFail($validated['conversation_id']);

        try {
            $this->projects->attachConversation($request->user(), $project, $conversation);
        } catch (ProjectException $exception) {
            return back()->withErrors(['conversation_id' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function detachConversation(Request $request, Project $project, Conversation $conversation): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'attach');
        $this->projects->detachConversation($request->user(), $project, $conversation);

        return back();
    }

    public function attachTopic(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'attach');
        $validated = $request->validate(['topic_id' => ['required', 'integer']]);
        $topic = Topic::query()->findOrFail($validated['topic_id']);

        try {
            $this->projects->attachTopic($request->user(), $project, $topic);
        } catch (ProjectException $exception) {
            return back()->withErrors(['topic_id' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function detachTopic(Request $request, Project $project, Topic $topic): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'attach');
        $this->projects->detachTopic($request->user(), $project, $topic);

        return back();
    }

    public function attachMemory(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'attach');
        $validated = $request->validate(['memory_id' => ['required', 'integer']]);
        $memory = Memory::query()->findOrFail($validated['memory_id']);

        try {
            $this->projects->attachMemory($request->user(), $project, $memory);
        } catch (ProjectException $exception) {
            return back()->withErrors(['memory_id' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function detachMemory(Request $request, Project $project, Memory $memory): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'attach');
        $this->projects->detachMemory($request->user(), $project, $memory);

        return back();
    }

    public function attachGroup(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'attach');
        $validated = $request->validate(['telegram_group_id' => ['required', 'integer']]);
        $group = TelegramGroup::query()->findOrFail($validated['telegram_group_id']);

        try {
            $this->projects->attachGroup($request->user(), $project, $group);
        } catch (ProjectException $exception) {
            return back()->withErrors(['telegram_group_id' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function detachGroup(Request $request, Project $project, TelegramGroup $telegramGroup): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'attach');
        $this->projects->detachGroup($request->user(), $project, $telegramGroup);

        return back();
    }

    public function attachPerson(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'attach');
        $validated = $request->validate([
            'person_id' => ['required', 'integer'],
            'role' => ['nullable', 'string', 'max:80'],
        ]);
        $person = Person::query()->findOrFail($validated['person_id']);

        try {
            $this->directory->attachPersonToProject($request->user(), $project, $person, $validated['role'] ?? null);
        } catch (DirectoryException $exception) {
            return back()->withErrors(['person_id' => $this->directoryMessage($exception)]);
        }

        return back();
    }

    public function detachPerson(Request $request, Project $project, Person $person): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'attach');
        $this->directory->detachPersonFromProject($request->user(), $project, $person);

        return back();
    }

    public function attachOrganization(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'attach');
        $validated = $request->validate([
            'organization_id' => ['required', 'integer'],
            'role' => ['nullable', 'string', 'max:80'],
        ]);
        $organization = Organization::query()->findOrFail($validated['organization_id']);

        try {
            $this->directory->attachOrganizationToProject($request->user(), $project, $organization, $validated['role'] ?? null);
        } catch (DirectoryException $exception) {
            return back()->withErrors(['organization_id' => $this->directoryMessage($exception)]);
        }

        return back();
    }

    public function detachOrganization(Request $request, Project $project, Organization $organization): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'attach');
        $this->directory->detachOrganizationFromProject($request->user(), $project, $organization);

        return back();
    }

    private function authorizeOwned(Request $request, Project $project, string $ability): void
    {
        if ((int) $project->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize($ability, $project);
    }

    private function messageFor(ProjectException $exception): string
    {
        return match ($exception->error) {
            'duplicate_name' => 'A project with this name already exists.',
            'invalid_name' => 'Project name is required.',
            'foreign_conversation' => 'That conversation cannot be attached.',
            'foreign_topic' => 'That topic cannot be attached.',
            'foreign_memory' => 'That memory cannot be attached.',
            default => 'Unable to update the project.',
        };
    }

    private function directoryMessage(DirectoryException $exception): string
    {
        return match ($exception->error) {
            'person_not_found' => 'That person cannot be attached.',
            'organization_not_found' => 'That organization cannot be attached.',
            'project_not_found' => 'That project cannot be updated.',
            default => 'Unable to update the project.',
        };
    }
}
