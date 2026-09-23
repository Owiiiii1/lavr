<?php

namespace App\Jobs;

use App\Enums\OwnerContextSourceStatus;
use App\Models\OwnerContextSource;
use App\Services\OwnerContext\OwnerContextExtractionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtractOwnerContextSourceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 150;

    public function __construct(
        public readonly int $sourceId,
        public readonly int $chunkOffset = 0,
    ) {
        $this->onQueue((string) config('owner_context.queue', 'memory'));
    }

    public function handle(OwnerContextExtractionService $extraction): void
    {
        $source = OwnerContextSource::query()->find($this->sourceId);

        if ($source === null) {
            return;
        }

        $extraction->ingest($source, $this->chunkOffset);
    }

    public function failed(?Throwable $exception): void
    {
        $source = OwnerContextSource::query()->find($this->sourceId);

        if ($source === null || $source->status !== OwnerContextSourceStatus::Processing) {
            return;
        }

        $processed = (int) ($source->metadata['progress']['processed_chunks'] ?? 0);
        $source->forceFill([
            'status' => $processed > 0 ? OwnerContextSourceStatus::Partial : OwnerContextSourceStatus::Failed,
            'metadata' => array_merge($source->metadata ?? [], [
                'processing' => 'partial',
                'error_category' => 'extraction_failed',
            ]),
        ])->save();

        Log::warning('owner context extraction failed', [
            'source_id' => $source->id,
            'user_id' => $source->user_id,
            'status' => $source->status->value,
            'processed_chunks' => $processed,
            'total_chunks' => (int) ($source->metadata['progress']['total_chunks'] ?? 0),
            'failed_chunks' => (int) ($source->metadata['progress']['ai_chunks_failed'] ?? 0),
            'error_category' => 'extraction_failed',
        ]);
    }
}
