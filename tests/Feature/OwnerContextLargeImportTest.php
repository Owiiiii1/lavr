<?php

namespace Tests\Feature;

use App\Enums\OwnerContextItemStatus;
use App\Enums\OwnerContextSourceStatus;
use App\Enums\UserRole;
use App\Models\OwnerContextItem;
use App\Models\OwnerContextSource;
use App\Models\User;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Ai\DTO\AiChatResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class OwnerContextLargeImportTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    protected function setUp(): void
    {
        parent::setUp();
        config(['owner_context.use_ai' => false]);
    }

    public function test_document_past_the_old_chunk_limit_is_fully_processed(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $sourceId = $this->import($user, $this->largeDocument());
            $source = OwnerContextSource::query()->findOrFail($sourceId);
            $progress = $source->metadata['progress'];

            $this->assertSame(OwnerContextSourceStatus::Ready, $source->status);
            $this->assertGreaterThan(50000, $progress['total_chars']);
            $this->assertGreaterThan(6, $progress['total_chunks']);
            $this->assertSame($progress['total_chunks'], $progress['processed_chunks']);
            $this->assertSame('complete', $progress['processing']);
            $this->assertSame('complete', $source->metadata['processing']);
        } finally {
            $this->cleanup($user);
        }
    }

    public function test_fact_near_the_end_of_a_large_document_is_extracted(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $this->import($user, $this->largeDocument());

            $this->assertTrue(
                OwnerContextItem::query()
                    ->where('user_id', $user->id)
                    ->where('value', 'Final document marker says the import reached the end.')
                    ->exists()
            );
        } finally {
            $this->cleanup($user);
        }
    }

    public function test_hard_cap_is_partial_and_does_not_pretend_the_file_is_complete(): void
    {
        $user = null;
        config([
            'owner_context.chunk_chars' => 500,
            'owner_context.hard_max_chunks' => 2,
        ]);

        try {
            $user = $this->owner();
            $sourceId = $this->import($user, str_repeat("# p\n\n", 800)."\n# End\n- [FACT|identity|owner|normal|0.95] Tail fact must stay outside the hard cap.\n");
            $source = OwnerContextSource::query()->findOrFail($sourceId);
            $progress = $source->metadata['progress'];

            $this->assertSame(OwnerContextSourceStatus::Partial, $source->status);
            $this->assertNotSame(OwnerContextSourceStatus::Ready, $source->status);
            $this->assertSame('partial', $source->metadata['processing']);
            $this->assertSame(2, $progress['processed_chunks']);
            $this->assertGreaterThan(2, $progress['total_chunks']);
            $this->assertSame(2, $progress['hard_max_chunks']);
            $this->assertFalse(
                OwnerContextItem::query()->where('user_id', $user->id)->where('value', 'like', 'Tail fact must stay%')->exists()
            );
        } finally {
            $this->cleanup($user);
        }
    }

    public function test_duplicate_claim_in_two_chunks_is_stored_once(): void
    {
        $user = null;
        config(['owner_context.chunk_chars' => 500]);

        try {
            $user = $this->owner();
            $line = '- [FACT|identity|owner|normal|0.95] Repeated claim across two chunks stays one item.';
            $this->import($user, "# A\n{$line}\n".str_repeat("# p\n\n", 200)."\n# B\n{$line}\n");

            $this->assertSame(1, OwnerContextItem::query()->where('user_id', $user->id)->where('value', 'like', 'Repeated claim across%')->count());
        } finally {
            $this->cleanup($user);
        }
    }

    public function test_retry_does_not_duplicate_existing_items(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $sourceId = $this->import($user, $this->largeDocument());
            $count = OwnerContextItem::query()->where('user_id', $user->id)->count();
            $this->assertGreaterThan(0, $count);

            OwnerContextSource::query()->whereKey($sourceId)->update(['status' => OwnerContextSourceStatus::Failed->value]);
            $this->actingAs($user)->postJson(route('jarvis.owner-context.retry', $sourceId))->assertOk();

            $this->assertSame($count, OwnerContextItem::query()->where('user_id', $user->id)->count());
            $this->assertSame(1, OwnerContextItem::query()->where('user_id', $user->id)->where('value', 'like', 'Final document marker%')->count());
            $this->assertSame(OwnerContextSourceStatus::Ready, OwnerContextSource::query()->findOrFail($sourceId)->status);
        } finally {
            $this->cleanup($user);
        }
    }

    public function test_failed_chunk_is_visible_and_keeps_successful_items(): void
    {
        $user = null;
        config([
            'owner_context.use_ai' => true,
            'owner_context.chunk_chars' => 400,
            'owner_context.chunks_per_run' => 4,
        ]);
        $logged = '';
        Log::listen(function ($event) use (&$logged): void {
            $logged .= $event->message.' '.json_encode($event->context);
        });

        try {
            $user = $this->owner();
            $this->mock(AiChatGateway::class, function ($mock): void {
                $mock->shouldReceive('chat')->andReturnUsing(function (mixed $configuration, AiChatRequest $request): AiChatResponse {
                    $content = $request->messages[0]->content;

                    if (str_contains($content, 'CHUNK-FAIL-TOKEN')) {
                        throw new RuntimeException('provider down');
                    }

                    if (str_contains($content, 'ALPHA-KEEP-FACT')) {
                        return new AiChatResponse(
                            '{"items":[{"value":"Alpha keep fact was recorded.","category":"identity","fact_class":"fact","scope_type":"owner","sensitivity":"normal","confidence":0.96,"evidence_excerpt":"alpha"}]}',
                            'fake',
                            'fake',
                        );
                    }

                    return new AiChatResponse('{"items":[]}', 'fake', 'fake');
                });
            });

            $sourceId = $this->import($user, "# A\nALPHA-KEEP-FACT belongs in the kept claim for this import.\n".str_repeat("pad\n", 120)."# B\nCHUNK-FAIL-TOKEN belongs in the failed claim for this import.\n".str_repeat("pad\n", 120));
            $source = OwnerContextSource::query()->findOrFail($sourceId);

            $this->assertSame(OwnerContextSourceStatus::Partial, $source->status);
            $this->assertSame('partial', $source->metadata['processing']);
            $this->assertGreaterThanOrEqual(1, $source->metadata['progress']['ai_chunks_failed']);
            $this->assertGreaterThanOrEqual(1, $source->metadata['progress']['ai_chunks_succeeded']);
            $this->assertSame(
                OwnerContextItemStatus::Accepted,
                OwnerContextItem::query()->where('user_id', $user->id)->where('value', 'Alpha keep fact was recorded.')->firstOrFail()->status,
            );
            $this->assertStringNotContainsString('CHUNK-FAIL-TOKEN', $logged);
            $this->assertStringNotContainsString('ALPHA-KEEP-FACT', $logged);
        } finally {
            $this->cleanup($user);
        }
    }

    private function largeDocument(): string
    {
        return "<!-- owner-context: structured -->\n".str_repeat("# p\n\n", 20000)."\n# End\n- [FACT|identity|owner|normal|0.95] Final document marker says the import reached the end.\n";
    }

    private function import(User $user, string $contents): int
    {
        $response = $this->actingAs($user)->post(route('jarvis.owner-context.import'), [
            'name' => 'Large '.Str::random(4),
            'file' => UploadedFile::fake()->createWithContent('master.md', $contents),
        ]);
        $response->assertCreated();

        return (int) $response->json('source.id');
    }

    private function owner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }

    private function cleanup(?User $user): void
    {
        if ($user !== null) {
            Storage::disk('local')->deleteDirectory('owner-context/'.$user->id);
        }

        $this->deleteTemporaryUser($user);
    }
}
