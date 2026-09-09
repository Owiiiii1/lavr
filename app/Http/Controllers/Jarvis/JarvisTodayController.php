<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Services\Workspace\TodayBriefService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JarvisTodayController extends Controller
{
    public function __construct(
        private readonly TodayBriefService $today,
    ) {}

    public function show(Request $request): Response
    {
        return Inertia::render('Jarvis/Today', [
            'today' => $this->today->forUser($request->user()),
        ]);
    }
}
