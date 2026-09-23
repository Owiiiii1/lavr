<?php

namespace App\Services\OwnerContext;

use App\Enums\OwnerContextSourceStatus;
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

    public function ingest(OwnerContextSource $source): void
    {
        if (in_array($source->status, [OwnerContextSourceStatus::Ready, OwnerContextSourceStatus::Archived], true)) {
            return;
        }

        $started = hrtime(true);
        $source->forceFill(['status' => OwnerContextSourceStatus::Processing])->save();

        try {
            $text = $this->read($source);
            $candidates = $this->parser->parse($text);
            $aiStatus = 'skipped';

            if ($this->shouldCallAi($text)) {
                try {
                    $candidates = array_merge($candidates, $this->fromAi($text));
                    $aiStatus = 'ok';
                } catch (Throwable) {
                    if ($candidates === []) {
                        throw new \RuntimeException('extraction_failed');
                    }

                    $aiStatus = 'failed';
                }
            }

            $stats = $this->store->save($source, $candidates);
            $duration = $this->duration($started);
            $stats['duration_ms'] = $duration;
            $stats['ai'] = $aiStatus;

            if ($stats['extracted'] === 0 && $aiStatus === 'failed') {
                $this->fail($source, $duration);

                return;
            }

            $source->forceFill([
                'status' => OwnerContextSourceStatus::Ready,
                'metadata' => array_merge($source->metadata ?? [], [
                    'result' => $stats,
                    'processing' => $aiStatus === 'failed' ? 'partial' : 'complete',
                ]),
            ])->save();

            Log::info('owner context extraction completed', $this->logContext($source, 'ready', $duration, $stats));
        } catch (Throwable) {
            $this->fail($source, $this->duration($started));
        }
    }

    private function shouldCallAi(string $text): bool
    {
        if (! (bool) config('owner_context.use_ai', true)) {
            return false;
        }

        return ! $this->parser->isStructured($text);
    }

    /**
     * @return list<OwnerContextCandidate>
     */
    private function fromAi(string $text): array
    {
        $configuration = $this->resolver->resolveAnalysis();
        $items = [];
        $chunks = $this->chunks($text);

        foreach ($chunks as $chunk) {
            $response = $this->gateway->chat($configuration, new AiChatRequest(
                model: (string) $configuration->model,
                systemPrompt: $this->prompts->system(),
                messages: [new AiChatMessage('user', $this->prompts->user($chunk))],
                parameters: [
                    'temperature' => 0.1,
                    'max_tokens' => 2000,
                ],
            ));
            $items = array_merge($items, $this->aiParser->parse((string) $response->text));
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function chunks(string $text): array
    {
        $size = max(1000, (int) config('owner_context.chunk_chars', 6000));
        $max = max(1, (int) config('owner_context.max_chunks', 6));
        $chunks = [];
        $rest = $text;

        while ($rest !== '' && count($chunks) < $max) {
            if (mb_strlen($rest) <= $size) {
                $chunks[] = $rest;

                break;
            }

            $slice = mb_substr($rest, 0, $size);
            $break = mb_strrpos($slice, "\n");
            $take = $break !== false && $break > (int) ($size / 2) ? $break : $size;
            $chunks[] = mb_substr($rest, 0, $take);
            $rest = ltrim(mb_substr($rest, $take));
        }

        return $chunks;
    }

    private function read(OwnerContextSource $source): string
    {
        $path = (string) $source->storage_path;

        if ($path === '' || ! Storage::disk((string) config('owner_context.disk', 'local'))->exists($path)) {
            throw new \RuntimeException('missing_source');
        }

        return (string) Storage::disk((string) config('owner_context.disk', 'local'))->get($path);
    }

    private function fail(OwnerContextSource $source, int $duration): void
    {
        $source->forceFill([
            'status' => OwnerContextSourceStatus::Failed,
            'metadata' => array_merge($source->metadata ?? [], [
                'error_category' => 'extraction_failed',
                'duration_ms' => $duration,
            ]),
        ])->save();

        Log::warning('owner context extraction failed', $this->logContext($source, 'failed', $duration, []));
    }

    /**
     * @param  array<string, int|string>  $stats
     * @return array<string, int|string>
     */
    private function logContext(OwnerContextSource $source, string $status, int $duration, array $stats): array
    {
        return [
            'source_id' => $source->id,
            'user_id' => $source->user_id,
            'status' => $status,
            'duration_ms' => $duration,
            'extracted' => (int) ($stats['extracted'] ?? 0),
            'accepted' => (int) ($stats['accepted'] ?? 0),
            'needs_review' => (int) ($stats['needs_review'] ?? 0),
            'error_category' => $status === 'failed' ? 'extraction_failed' : '',
        ];
    }

    private function duration(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
