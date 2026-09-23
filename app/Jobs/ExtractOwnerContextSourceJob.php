<?php

namespace App\Jobs;

use App\Models\OwnerContextSource;
use App\Services\OwnerContext\OwnerContextExtractionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractOwnerContextSourceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 150;

    public function __construct(public readonly int $sourceId)
    {
        $this->onQueue((string) config('owner_context.queue', 'memory'));
    }

    public function handle(OwnerContextExtractionService $extraction): void
    {
        $source = OwnerContextSource::query()->find($this->sourceId);

        if ($source === null) {
            return;
        }

        $extraction->ingest($source);
    }
}
