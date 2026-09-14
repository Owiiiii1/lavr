<?php

namespace App\Http\Controllers\Jarvis;

use App\Enums\ProactiveAuditAction;
use App\Enums\ProactiveProposalStatus;
use App\Http\Controllers\Controller;
use App\Models\ProactiveProposal;
use App\Services\OperationalControl\ProactiveProposalExecutor;
use App\Services\OperationalControl\ProactiveProposalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JarvisProactiveController extends Controller
{
    public function __construct(
        private readonly ProactiveProposalService $proposals,
        private readonly ProactiveProposalExecutor $executor,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ProactiveProposal::class);
        $user = $request->user();

        $base = ProactiveProposal::query()
            ->with(['person:id,display_name', 'project:id,name', 'commitment:id,title', 'event'])
            ->where('user_id', $user->id);

        $pending = (clone $base)
            ->where('status', ProactiveProposalStatus::Pending)
            ->where(function ($query): void {
                $query->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
            })
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END")
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        $serialize = fn (ProactiveProposal $proposal): array => $this->proposals->serialize($proposal);

        return Inertia::render('Jarvis/Proactive/Index', [
            'needs_attention' => $pending
                ->filter(fn (ProactiveProposal $proposal): bool => in_array($proposal->severity?->value, ['critical', 'high'], true))
                ->map($serialize)
                ->values()
                ->all(),
            'suggested' => $pending
                ->filter(fn (ProactiveProposal $proposal): bool => in_array($proposal->severity?->value, ['normal', 'low'], true))
                ->map($serialize)
                ->values()
                ->all(),
            'waiting_approval' => $pending
                ->filter(fn (ProactiveProposal $proposal): bool => $proposal->requires_confirmation)
                ->map($serialize)
                ->values()
                ->all(),
            'recently_handled' => (clone $base)
                ->whereIn('status', [ProactiveProposalStatus::Executed, ProactiveProposalStatus::Approved, ProactiveProposalStatus::Expired])
                ->orderByDesc('acted_at')
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map($serialize)
                ->values()
                ->all(),
            'dismissed' => (clone $base)
                ->where('status', ProactiveProposalStatus::Dismissed)
                ->orderByDesc('acted_at')
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map($serialize)
                ->values()
                ->all(),
        ]);
    }

    public function show(Request $request, ProactiveProposal $proposal): Response
    {
        if ((int) $proposal->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('view', $proposal);
        $proposal->load(['person:id,display_name', 'project:id,name', 'commitment:id,title', 'event']);

        return Inertia::render('Jarvis/Proactive/Show', [
            'proposal' => $this->proposals->serialize($proposal),
        ]);
    }

    public function approve(Request $request, ProactiveProposal $proposal): RedirectResponse
    {
        $this->guard($request, $proposal);
        $validated = $request->validate([
            'draft_body' => ['nullable', 'string', 'max:2000'],
            'send' => ['sometimes', 'boolean'],
        ]);
        $this->executor->approve($request->user(), $proposal, $validated, (bool) ($validated['send'] ?? false));

        return back();
    }

    public function dismiss(Request $request, ProactiveProposal $proposal): RedirectResponse
    {
        $this->guard($request, $proposal);
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'in:not_relevant,already_handled,wrong_detection,dont_alert_this_type'],
        ]);
        $this->executor->dismiss($request->user(), $proposal, $validated['reason'] ?? null);

        return back();
    }

    public function snooze(Request $request, ProactiveProposal $proposal): RedirectResponse
    {
        $this->guard($request, $proposal);
        $validated = $request->validate([
            'when' => ['required', 'string', 'max:32'],
        ]);
        $this->executor->snooze($request->user(), $proposal, $validated['when']);

        return back();
    }

    public function update(Request $request, ProactiveProposal $proposal): RedirectResponse
    {
        $this->guard($request, $proposal);
        $validated = $request->validate([
            'draft_body' => ['required', 'string', 'max:2000'],
        ]);
        $payload = is_array($proposal->action_payload_json) ? $proposal->action_payload_json : [];
        $payload['draft_body'] = $validated['draft_body'];
        $payload['draft_status'] = 'draft';
        $proposal->forceFill(['action_payload_json' => $payload])->save();
        $this->proposals->audit($proposal, ProactiveAuditAction::Edited, 'edited');

        return back();
    }

    private function guard(Request $request, ProactiveProposal $proposal): void
    {
        if ((int) $proposal->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('update', $proposal);
    }
}
