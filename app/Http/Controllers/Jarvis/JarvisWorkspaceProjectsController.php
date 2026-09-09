<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Projects\ProjectService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JarvisWorkspaceProjectsController extends Controller
{
    public function __construct(
        private readonly ProjectService $projects,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $items = $this->projects->listForOwner($request->user())->map(static fn (Project $project): array => [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status->value,
            'updated_at' => optional($project->updated_at)?->toIso8601String(),
        ])->all();

        return Inertia::render('Jarvis/Projects', [
            'projects' => $items,
            'hint' => 'Текущие рабочие контейнеры. Это ещё не целевая модель бизнес-контекста Phase 4.',
        ]);
    }

    public function show(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        return Inertia::render('Jarvis/ProjectShow', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status->value,
                'updated_at' => optional($project->updated_at)?->toIso8601String(),
            ],
            'hint' => 'Текущий work container. Не бизнес-контекст Phase 4.',
            'admin_href' => route('projects.show', $project),
        ]);
    }
}
