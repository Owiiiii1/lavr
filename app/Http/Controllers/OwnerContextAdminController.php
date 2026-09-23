<?php

namespace App\Http\Controllers;

use App\Services\OwnerContext\OwnerContextCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OwnerContextAdminController extends Controller
{
    public function __construct(private readonly OwnerContextCatalog $catalog) {}

    public function index(Request $request): Response
    {
        $payload = $this->catalog->payload($request->user(), []);
        $sources = collect($payload['sources'])->map(fn (array $source): array => [
            'id' => $source['id'],
            'name' => $source['name'],
            'status' => $source['status'],
            'source_date' => $source['source_date'],
            'extracted' => $source['extracted'],
            'accepted' => $source['accepted'],
            'needs_review' => $source['needs_review'],
            'error_category' => $source['error_category'],
        ])->all();

        return Inertia::render('OwnerContext/Index', [
            'counts' => $payload['counts'],
            'sources' => $sources,
        ]);
    }
}
