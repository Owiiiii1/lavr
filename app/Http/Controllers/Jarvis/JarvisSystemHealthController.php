<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Services\Readiness\LavrDiagnosticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JarvisSystemHealthController extends Controller
{
    public function show(Request $request, LavrDiagnosticsService $diagnostics): Response
    {
        abort_unless($request->user()?->isOwner(), 403);

        return Inertia::render('Jarvis/SystemHealth', [
            'health' => $diagnostics->snapshot(),
        ]);
    }
}
