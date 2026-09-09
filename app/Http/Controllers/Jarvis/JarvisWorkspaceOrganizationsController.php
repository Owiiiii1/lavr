<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Directory\DirectoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JarvisWorkspaceOrganizationsController extends Controller
{
    public function __construct(
        private readonly DirectoryService $directory,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Organization::class);

        $organizations = Organization::query()
            ->where('user_id', $request->user()->id)
            ->withCount('projects')
            ->orderBy('name')
            ->get();

        return Inertia::render('Jarvis/Organizations', [
            'organizations' => $organizations->map(fn (Organization $organization): array => $this->directory->serializeOrganization($organization))->values()->all(),
        ]);
    }

    public function show(Request $request, Organization $organization): Response
    {
        if ((int) $organization->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('view', $organization);

        return Inertia::render('Jarvis/OrganizationShow', [
            'organization' => $this->directory->serializeOrganization($organization->load('projects')),
        ]);
    }
}
