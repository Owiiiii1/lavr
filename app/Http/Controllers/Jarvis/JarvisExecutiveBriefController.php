<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Models\ExecutiveBrief;
use App\Services\ExecutiveBrief\ExecutiveBriefService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JarvisExecutiveBriefController extends Controller
{
    public function __construct(
        private readonly ExecutiveBriefService $briefs,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ExecutiveBrief::class);

        $paginator = $this->briefs->paginate($request->user(), [
            'date' => (string) $request->query('date', ''),
            'type' => (string) $request->query('type', ''),
            'status' => (string) $request->query('status', ''),
        ]);

        return Inertia::render('Jarvis/Briefs/Index', [
            'briefs' => $paginator->through(fn (ExecutiveBrief $brief): array => $this->briefs->serializeSummary($brief)),
            'filters' => [
                'date' => (string) $request->query('date', ''),
                'type' => (string) $request->query('type', ''),
                'status' => (string) $request->query('status', ''),
            ],
        ]);
    }

    public function show(Request $request, ExecutiveBrief $brief): Response
    {
        if ((int) $brief->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('view', $brief);

        return Inertia::render('Jarvis/Briefs/Show', [
            'brief' => $this->briefs->serialize($brief),
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $this->authorize('create', ExecutiveBrief::class);

        $brief = $this->briefs->generateNow($request->user());

        return redirect()->route('jarvis.briefs.show', $brief);
    }
}
