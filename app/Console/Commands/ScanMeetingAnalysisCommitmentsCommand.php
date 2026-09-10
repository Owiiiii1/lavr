<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Models\MeetingAnalysis;
use App\Models\User;
use App\Services\Commitments\CommitmentPromotionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ScanMeetingAnalysisCommitmentsCommand extends Command
{
    protected $signature = 'commitments:scan-meeting-analysis
        {--meeting= : Meeting id}
        {--from= : From date YYYY-MM-DD}
        {--to= : To date YYYY-MM-DD}
        {--apply : Create/update first-class commitments}
        {--limit=50}';

    protected $description = 'Scan meeting analyses for commitment candidates. Dry-run by default.';

    public function handle(CommitmentPromotionService $promotion): int
    {
        $apply = (bool) $this->option('apply');
        $meetingId = $this->option('meeting') !== null ? (int) $this->option('meeting') : 0;
        $query = MeetingAnalysis::query()
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->limit(max(1, (int) $this->option('limit')));

        if ($meetingId > 0) {
            $query->where('meeting_id', $meetingId);
        }

        if (is_string($this->option('from')) && $this->option('from') !== '') {
            $query->where('created_at', '>=', CarbonImmutable::parse((string) $this->option('from'))->startOfDay());
        }

        if (is_string($this->option('to')) && $this->option('to') !== '') {
            $query->where('created_at', '<=', CarbonImmutable::parse((string) $this->option('to'))->endOfDay());
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($query->get() as $analysis) {
            $meeting = Meeting::query()->find($analysis->meeting_id);
            $user = $meeting !== null ? User::query()->find($meeting->user_id) : null;

            if ($meeting === null || $user === null) {
                continue;
            }

            if (! $apply) {
                $result = is_array($analysis->result_json) ? $analysis->result_json : [];
                $items = is_array($result['commitments_detected'] ?? null) ? $result['commitments_detected'] : [];
                $this->line('Dry-run meeting '.$meeting->id.' analysis '.$analysis->id.': '.count($items).' detected item(s).');
                $skipped += count($items);

                continue;
            }

            $outcome = $promotion->promoteFromMeetingAnalysis($user, $meeting, $analysis, true);
            $created += count($outcome['created']);
            $updated += count($outcome['updated']);
            $skipped += count($outcome['skipped']);
        }

        $this->info(($apply ? 'Applied' : 'Dry-run').": created {$created}, updated {$updated}, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
