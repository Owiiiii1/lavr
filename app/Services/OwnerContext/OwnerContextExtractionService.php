<?php

namespace App\Services\OwnerContext;

use App\Enums\OwnerContextSourceStatus;
use App\Jobs\ExtractOwnerContextSourceJob;
use App\Models\AiRoleSetting;
use App\Models\OwnerContextSource;
use App\Services\Ai\AiConfigurationResolver;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\OwnerContext\DTO\OwnerContextCandidate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class OwnerContextExtractionService
{
    public function __construct(
        private readonly OwnerContextDocumentParser $parser,
        private readonly OwnerContextAiParser $aiParser,
        private readonly OwnerContextExtractionPrompt $prompts,
        private readonly OwnerContextStore $store,
        private readonly AiConfigurationResolver $resolver,
        private readonly AiChatGateway $gateway,
    ) {}

    public function ingest(OwnerContextSource $source, int $offset = 0): void
    {
        if (in_array($source->status, [OwnerContextSourceStatus::Ready, OwnerContextSourceStatus::Archived], true)) {
            return;
        }

        $started = hrtime(true);
        $offset = max(0, $offset);

        try {
            $text = $this->read($source);
            $chunks = $this->chunks($text);
            $total = count($chunks);
            $hard = max(1, (int) config('owner_context.hard_max_chunks', 100));
            $limit = min($total, $hard);
            $batch = max(1, (int) config('owner_context.chunks_per_run', 8));

            if ($total === 0 || $offset >= $limit) {
                $this->finish($source, $this->progressFrom($text, $total, $offset, $hard, 0, 0), $this->duration($started));

                return;
            }

            $source->forceFill(['status' => OwnerContextSourceStatus::Processing])->save();
            $end = min($offset + $batch, $limit);
            $useAi = $this->shouldCallAi($text);
            $configuration = $useAi ? $this->analysisConfiguration() : null;
            $prior = $offset === 0 ? [] : ($source->metadata['progress'] ?? []);
            $succeeded = $offset === 0 ? 0 : (int) ($prior['ai_chunks_succeeded'] ?? 0);
            $failed = $offset === 0 ? 0 : (int) ($prior['ai_chunks_failed'] ?? 0);
            $failedIndexes = $offset === 0 ? [] : array_map('intval', $prior['failed_chunk_indexes'] ?? []);
            $candidates = [];

            for ($index = $offset; $index < $end; $index++) {
                $candidates = array_merge($candidates, $this->parser->parse($chunks[$index]));

                if (! $useAi) {
                    continue;
                }

                if ($configuration === null) {
                    $failed++;
                    $failedIndexes[] = $index;

                    continue;
                }

                try {
                    $candidates = array_merge($candidates, $this->aiChunk($configuration, $chunks[$index]));
                    $succeeded++;
                    $failedIndexes = array_values(array_filter(
                        $failedIndexes,
                        static fn (int $failedIndex): bool => $failedIndex !== $index,
                    ));
                } catch (Throwable) {
                    $failed++;
                    $failedIndexes[] = $index;
                }
            }

            $batchStats = $candidates === [] ? $this->emptyStats() : $this->store->save($source, $candidates);
            $stats = $this->accumulate(
                $offset === 0 ? null : ($source->metadata['result'] ?? null),
                $batchStats,
                $useAi,
                $succeeded,
                $failed,
            );
            $source->forceFill([
                'metadata' => array_merge($source->metadata ?? [], ['result' => $stats]),
            ])->save();

            $progress = $this->progressFrom($text, $total, $end, $hard, $succeeded, $failed, $failedIndexes);
            $duration = ($offset === 0 ? 0 : (int) ($source->metadata['duration_ms'] ?? 0)) + $this->duration($started);
            $this->writeProgress($source, $progress, OwnerContextSourceStatus::Processing, $duration);

            if ($end < $limit) {
                ExtractOwnerContextSourceJob::dispatch($source->id, $end);

                return;
            }

            $this->finish($source, $progress, $duration);
        } catch (Throwable) {
            $processed = (int) ($source->fresh()->metadata['progress']['processed_chunks'] ?? 0);
            if ($processed > 0) {
                $source->fresh()->forceFill([
                    'status' => OwnerContextSourceStatus::Partial,
                    'metadata' => array_merge($source->fresh()->metadata ?? [], [
                        'processing' => 'partial',
                        'error_category' => 'extraction_failed',
                    ]),
                ])->save();
                Log::warning('owner context extraction partial', $this->logContext($source->fresh(), 'partial', $this->duration($started)));

                return;
            }

            $this->fail($source, $this->duration($started));
        }
    }

    /**
     * @param  array<string, int|string|list<int>>  $progress
     */
    private function finish(OwnerContextSource $source, array $progress, int $duration): void
    {
        $source->refresh();
        $items = $source->items()->count();
        $capped = (int) $progress['processed_chunks'] < (int) $progress['total_chunks'];
        $aiFailed = (int) $progress['ai_chunks_failed'];
        $aiSucceeded = (int) $progress['ai_chunks_succeeded'];

        if ($aiFailed > 0 && $aiSucceeded === 0 && $items === 0 && ! $capped) {
            $this->fail($source, $duration, $progress);

            return;
        }

        $partial = $capped || $aiFailed > 0;
        $progress['processing'] = $partial ? 'partial' : 'complete';
        $status = $partial ? OwnerContextSourceStatus::Partial : OwnerContextSourceStatus::Ready;
        $this->writeProgress($source, $progress, $status, $duration);
        $fresh = $source->fresh();
        $result = $fresh->metadata['result'] ?? $this->emptyStats();
        $result['duration_ms'] = $duration;
        $fresh->forceFill([
            'metadata' => array_merge($fresh->metadata ?? [], ['result' => $result]),
        ])->save();
        Log::info('owner context extraction completed', $this->logContext($fresh, $status->value, $duration));
    }

    /**
     * @param  array<string, int|string>|null  $prior
     * @param  array<string, int|string>  $batch
     * @return array<string, int|string>
     */
    private function accumulate(?array $prior, array $batch, bool $useAi, int $succeeded, int $failed): array
    {
        $stats = $prior ?? $this->emptyStats();

        foreach (['extracted', 'accepted', 'needs_review', 'candidates', 'linked', 'historical', 'private_or_restricted', 'skipped_duplicates'] as $key) {
            $stats[$key] = (int) ($stats[$key] ?? 0) + (int) ($batch[$key] ?? 0);
        }

        $stats['ai'] = $this->aiStatus($useAi, $succeeded, $failed);

        return $stats;
    }

    /**
     * @return array<string, int|string>
     */
    private function emptyStats(): array
    {
        return [
            'extracted' => 0,
            'accepted' => 0,
            'needs_review' => 0,
            'candidates' => 0,
            'linked' => 0,
            'historical' => 0,
            'private_or_restricted' => 0,
            'skipped_duplicates' => 0,
            'ai' => 'skipped',
        ];
    }

    private function aiStatus(bool $useAi, int $succeeded, int $failed): string
    {
        if (! $useAi) {
            return 'skipped';
        }

        if ($failed > 0) {
            return 'failed';
        }

        return $succeeded > 0 ? 'ok' : 'skipped';
    }

    /**
     * @param  array<string, int|string|list<int>>  $progress
     */
    private function writeProgress(
        OwnerContextSource $source,
        array $progress,
        OwnerContextSourceStatus $status,
        int $duration,
    ): void {
        $failedChunks = (int) ($progress['ai_chunks_failed'] ?? 0);

        $source->forceFill([
            'status' => $status,
            'metadata' => array_merge($source->metadata ?? [], [
                'progress' => $progress,
                'processing' => $progress['processing'],
                'duration_ms' => $duration,
                'error_category' => $status === OwnerContextSourceStatus::Failed || $failedChunks > 0 ? 'extraction_failed' : null,
            ]),
        ])->save();
    }

    /**
     * @param  list<int>  $failedIndexes
     * @return array<string, int|string|list<int>>
     */
    private function progressFrom(
        string $text,
        int $total,
        int $processed,
        int $hard,
        int $succeeded,
        int $failed,
        array $failedIndexes = [],
    ): array {
        $capped = $processed < $total;

        return [
            'total_chars' => mb_strlen($text),
            'chunk_chars' => $this->chunkSize(),
            'total_chunks' => $total,
            'processed_chunks' => $processed,
            'hard_max_chunks' => $hard,
            'processing' => $capped || $failed > 0 ? 'partial' : 'complete',
            'ai_chunks_succeeded' => $succeeded,
            'ai_chunks_failed' => $failed,
            'failed_chunk_indexes' => array_values(array_unique($failedIndexes)),
        ];
    }

    private function shouldCallAi(string $text): bool
    {
        if (! (bool) config('owner_context.use_ai', true)) {
            return false;
        }

        return ! $this->parser->isStructured($text);
    }

    private function analysisConfiguration(): ?AiRoleSetting
    {
        try {
            return $this->resolver->resolveAnalysis();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<OwnerContextCandidate>
     */
    private function aiChunk(AiRoleSetting $configuration, string $chunk): array
    {
        $response = $this->gateway->chat($configuration, new AiChatRequest(
            model: (string) $configuration->model,
            systemPrompt: $this->prompts->system(),
            messages: [new AiChatMessage('user', $this->prompts->user($chunk))],
            parameters: [
                'temperature' => 0.1,
                'max_tokens' => 2000,
            ],
        ));

        return $this->aiParser->parse((string) $response->text);
    }

    /**
     * @return list<string>
     */
    private function chunks(string $text): array
    {
        $size = $this->chunkSize();
        $chunks = [];
        $rest = $text;

        while ($rest !== '') {
            if (mb_strlen($rest) <= $size) {
                $chunks[] = $rest;

                break;
            }

            $take = $this->splitAt(mb_substr($rest, 0, $size), $size);
            $chunks[] = mb_substr($rest, 0, $take);
            $rest = ltrim(mb_substr($rest, $take));
        }

        return $chunks;
    }

    private function splitAt(string $slice, int $size): int
    {
        $minimum = (int) ($size / 2);

        foreach (["\n#", "\n\n", "\n"] as $boundary) {
            $position = mb_strrpos($slice, $boundary);

            if ($position !== false && $position > $minimum) {
                return $position;
            }
        }

        return $size;
    }

    private function chunkSize(): int
    {
        return max(200, (int) config('owner_context.chunk_chars', 6000));
    }

    private function read(OwnerContextSource $source): string
    {
        $path = (string) $source->storage_path;

        if ($path === '' || ! Storage::disk((string) config('owner_context.disk', 'local'))->exists($path)) {
            throw new \RuntimeException('missing_source');
        }

        return (string) Storage::disk((string) config('owner_context.disk', 'local'))->get($path);
    }

    /**
     * @param  array<string, int|string|list<int>>  $progress
     */
    private function fail(OwnerContextSource $source, int $duration, array $progress = []): void
    {
        $metadata = array_merge($source->metadata ?? [], [
            'error_category' => 'extraction_failed',
            'duration_ms' => $duration,
            'processing' => 'partial',
        ]);

        if ($progress !== []) {
            $progress['processing'] = 'partial';
            $metadata['progress'] = $progress;
        }

        $source->forceFill([
            'status' => OwnerContextSourceStatus::Failed,
            'metadata' => $metadata,
        ])->save();

        Log::warning('owner context extraction failed', $this->logContext($source, 'failed', $duration));
    }

    /**
     * @return array<string, int|string>
     */
    private function logContext(OwnerContextSource $source, string $status, int $duration): array
    {
        $progress = $source->metadata['progress'] ?? [];

        return [
            'source_id' => $source->id,
            'user_id' => $source->user_id,
            'status' => $status,
            'duration_ms' => $duration,
            'total_chunks' => (int) ($progress['total_chunks'] ?? 0),
            'processed_chunks' => (int) ($progress['processed_chunks'] ?? 0),
            'failed_chunks' => (int) ($progress['ai_chunks_failed'] ?? 0),
            'error_category' => $status === 'failed' ? 'extraction_failed' : '',
        ];
    }

    private function duration(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
