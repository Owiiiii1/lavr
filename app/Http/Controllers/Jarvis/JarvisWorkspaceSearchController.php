<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Services\Directory\DirectorySearchService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JarvisWorkspaceSearchController extends Controller
{
    public function __construct(
        private readonly DirectorySearchService $search,
    ) {}

    public function show(Request $request): Response
    {
        $query = trim((string) $request->query('q', ''));
        $results = $query === ''
            ? ['people' => [], 'organizations' => [], 'projects' => []]
            : $this->search->search($request->user(), $query);

        return Inertia::render('Jarvis/DirectorySearch', [
            'query' => $query,
            'results' => $results,
        ]);
    }
}
