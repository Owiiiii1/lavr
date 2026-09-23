<?php

namespace App\Http\Controllers\Jarvis;

use App\Enums\OwnerContextCategory;
use App\Enums\OwnerContextScopeType;
use App\Http\Controllers\Controller;
use App\Models\OwnerContextItem;
use App\Models\OwnerContextSource;
use App\Services\OwnerContext\OwnerContextCatalog;
use App\Services\OwnerContext\OwnerContextImportService;
use App\Services\OwnerContext\OwnerContextItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JarvisOwnerContextController extends Controller
{
    public function __construct(
        private readonly OwnerContextCatalog $catalog,
        private readonly OwnerContextImportService $imports,
        private readonly OwnerContextItemService $items,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'tab' => ['nullable', 'string', 'max:32'],
            'fact_class' => ['nullable', 'string', 'max:32'],
            'category' => ['nullable', 'string', 'max:64'],
            'scope_type' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'max:32'],
            'sensitivity' => ['nullable', 'string', 'max:32'],
            'source_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:180'],
        ]);

        return response()->json($this->catalog->payload($request->user(), $filters));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'extensions:txt,md,markdown', 'max:2048'],
            'name' => ['required', 'string', 'max:180'],
            'source_date' => ['nullable', 'date'],
        ]);

        $source = $this->imports->storeUpload(
            $request->user(),
            $validated['file'],
            $validated['name'],
            $validated['source_date'] ?? null,
        );

        return response()->json([
            'source' => [
                'id' => $source->id,
                'status' => $source->fresh()->status->value,
            ],
        ], 201);
    }

    public function retry(Request $request, int $source): JsonResponse
    {
        $row = $this->source($request, $source);
        $this->imports->retry($row);

        return response()->json(['status' => $row->fresh()->status->value]);
    }

    public function archive(Request $request, int $source): JsonResponse
    {
        $row = $this->source($request, $source);
        $this->authorize('update', $row);
        $this->imports->archive($row);

        return response()->json(['status' => $row->status->value]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $item = $this->items->addManual($request->user(), $this->itemFields($request));

        return response()->json(['item' => ['id' => $item->id, 'status' => $item->status->value]], 201);
    }

    public function updateItem(Request $request, int $item): JsonResponse
    {
        $row = $this->item($request, $item);
        $updated = $this->items->edit($request->user(), $row, $this->itemFields($request, false));

        return response()->json(['item' => ['id' => $updated->id, 'status' => $updated->status->value]]);
    }

    public function accept(Request $request, int $item): JsonResponse
    {
        $row = $this->items->accept($request->user(), $this->item($request, $item));

        return response()->json(['status' => $row->status->value]);
    }

    public function reject(Request $request, int $item): JsonResponse
    {
        $row = $this->items->reject($request->user(), $this->item($request, $item));

        return response()->json(['status' => $row->status->value]);
    }

    public function needsReview(Request $request, int $item): JsonResponse
    {
        $row = $this->items->markNeedsReview($request->user(), $this->item($request, $item));

        return response()->json(['status' => $row->status->value]);
    }

    public function link(Request $request, int $item): JsonResponse
    {
        $validated = $request->validate([
            'scope_type' => ['required', 'in:person,project,organization'],
            'scope_id' => ['required', 'integer'],
        ]);
        $row = $this->items->link(
            $request->user(),
            $this->item($request, $item),
            OwnerContextScopeType::from($validated['scope_type']),
            (int) $validated['scope_id'],
        );

        return response()->json(['scope_id' => $row->scope_id]);
    }

    public function supersede(Request $request, int $item): JsonResponse
    {
        $validated = $request->validate([
            'previous_id' => ['required', 'integer'],
        ]);
        $current = $this->item($request, $item);
        $previous = $this->item($request, (int) $validated['previous_id']);
        $row = $this->items->supersede($request->user(), $current, $previous);

        return response()->json([
            'status' => $row->status->value,
            'supersedes_id' => $row->supersedes_id,
            'previous_status' => $previous->fresh()->status->value,
        ]);
    }

    public function acceptSafe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
        ]);

        return response()->json($this->items->acceptSafe($request->user(), $validated['ids'] ?? []));
    }

    public function rejectSelected(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        return response()->json([
            'rejected' => $this->items->rejectSelected($request->user(), $validated['ids']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function itemFields(Request $request, bool $required = true): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return $request->validate([
            'value' => [$presence, 'string', 'min:8', 'max:500'],
            'category' => [$presence, 'in:'.implode(',', OwnerContextCategory::values())],
            'fact_class' => [$presence, 'in:fact,current,historical,analysis,to_verify'],
            'scope_type' => [$presence, 'in:owner,business,organization,project,person'],
            'scope_label' => ['nullable', 'string', 'max:180'],
            'scope_id' => ['nullable', 'integer'],
            'sensitivity' => [$presence, 'in:normal,private,restricted'],
            'effective_from' => ['nullable', 'date'],
            'evidence_excerpt' => ['nullable', 'string', 'max:280'],
        ]);
    }

    private function source(Request $request, int $id): OwnerContextSource
    {
        return OwnerContextSource::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);
    }

    private function item(Request $request, int $id): OwnerContextItem
    {
        return OwnerContextItem::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);
    }
}
