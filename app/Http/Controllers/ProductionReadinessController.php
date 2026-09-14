<?php

namespace App\Http\Controllers;

use App\Services\Readiness\LavrDiagnosticsService;
use App\Services\Readiness\SecretAuditService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductionReadinessController extends Controller
{
    public function show(Request $request, LavrDiagnosticsService $diagnostics, SecretAuditService $secrets): Response
    {
        abort_unless($request->user()?->isOwner(), 403);

        return Inertia::render('ProductionReadiness', [
            'readiness' => $diagnostics->snapshot(),
            'secrets' => $secrets->scanTracked(),
        ]);
    }
}
