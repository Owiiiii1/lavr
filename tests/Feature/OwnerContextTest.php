<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Enums\OwnerContextFactClass;
use App\Enums\OwnerContextItemStatus;
use App\Enums\OwnerContextScopeType;
use App\Enums\OwnerContextSourceStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Jobs\ExtractOwnerContextSourceJob;
use App\Models\AiRoleSetting;
use App\Models\Message;
use App\Models\OwnerContextItem;
use App\Models\OwnerContextSource;
use App\Models\Person;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Conversations\ConversationContextBuilder;
use App\Services\Conversations\ConversationService;
use App\Services\OwnerContext\DTO\OwnerContextQuery;
use App\Services\OwnerContext\OwnerContextEntityResolver;
use App\Services\OwnerContext\OwnerContextItemService;
use App\Services\OwnerContext\OwnerContextRetriever;
use App\Services\Projects\ProjectNameNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class OwnerContextTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    protected function setUp(): void
    {
        parent::setUp();
        config(['owner_context.use_ai' => false]);
    }

    public function test_upload_queues_extraction_without_running_it(): void
    {
        $user = null;
        Queue::fake();

        try {
            $user = $this->owner();
            $response = $this->actingAs($user)->post(route('jarvis.owner-context.import'), [
                'name' => 'Queued',
                'file' => UploadedFile::fake()->createWithContent('note.md', "# Note\n- [FACT|identity|owner|normal|0.95] Default language is Ukrainian.\n"),
            ]);

            $response->assertCreated();
            $sourceId = (int) $response->json('source.id');
            $this->assertSame(OwnerContextSourceStatus::Uploaded, OwnerContextSource::query()->findOrFail($sourceId)->status);
            Queue::assertPushed(ExtractOwnerContextSourceJob::class, fn (ExtractOwnerContextSourceJob $job): bool => $job->sourceId === $sourceId);
            $this->assertSame(0, OwnerContextItem::query()->where('user_id', $user->id)->count());
        } finally {
            $this->cleanup($user);
        }
    }

    public function test_structured_import_classifies_conflicts_and_does_not_create_people(): void
    {
        $user = null;
        $logged = '';
        Log::listen(function ($event) use (&$logged): void {
            $logged .= $event->message.' '.json_encode($event->context);
        });

        try {
            $user = $this->owner();
            $before = Person::query()->where('user_id', $user->id)->count();
            Person::factory()->create([
                'user_id' => $user->id,
                'first_name' => 'Kateryna',
                'last_name' => 'Vale',
                'display_name' => 'Kateryna Vale',
                'normalized_name' => ProjectNameNormalizer::normalize('Kateryna Vale'),
                'primary_email' => 'kateryna.vale.'.Str::lower(Str::random(6)).'@example.test',
            ]);
            Project::query()->create([
                'user_id' => $user->id,
                'name' => 'North Pavilion',
                'normalized_name' => ProjectNameNormalizer::normalize('North Pavilion'),
                'status' => ProjectStatus::Active,
            ]);

            $sourceId = $this->import($user, (string) file_get_contents(base_path('tests/Fixtures/owner_context_master.md')));
            $source = OwnerContextSource::query()->findOrFail($sourceId);
            $result = $source->metadata['result'];

            $this->assertSame(OwnerContextSourceStatus::Ready, $source->status);
            $this->assertSame(14, $result['extracted']);
            $this->assertSame(7, $result['accepted']);
            $this->assertSame(2, $result['needs_review']);
            $this->assertSame(4, $result['linked']);
            $this->assertSame(2, $result['historical']);
            $this->assertSame(2, $result['private_or_restricted']);
            $this->assertSame(1, $result['skipped_duplicates']);
            $this->assertSame($before + 1, Person::query()->where('user_id', $user->id)->count());

            $language = OwnerContextItem::query()->where('user_id', $user->id)->where('value', 'Default language is Ukrainian.')->get();
            $this->assertCount(1, $language);
            $this->assertSame(OwnerContextFactClass::Fact, $language->first()->fact_class);
            $this->assertSame(OwnerContextItemStatus::Accepted, $language->first()->status);

            $analysis = OwnerContextItem::query()->where('user_id', $user->id)->where('category', 'ceo_development')->firstOrFail();
            $this->assertSame(OwnerContextFactClass::Analysis, $analysis->fact_class);
            $this->assertSame(OwnerContextItemStatus::Candidate, $analysis->status);

            $verify = OwnerContextItem::query()->where('user_id', $user->id)->where('fact_class', OwnerContextFactClass::ToVerify)->firstOrFail();
            $this->assertSame(OwnerContextItemStatus::Candidate, $verify->status);

            $former = OwnerContextItem::query()->where('user_id', $user->id)->where('value', 'like', 'Former Employee%')->firstOrFail();
            $this->assertNull($former->scope_id);
            $this->assertSame(OwnerContextFactClass::Historical, $former->fact_class);
            $this->assertSame(OwnerContextItemStatus::NeedsReview, $former->status);

            $conflict = OwnerContextItem::query()->where('user_id', $user->id)->where('value', 'like', 'Sergey Stone%')->firstOrFail();
            $this->assertSame(OwnerContextItemStatus::NeedsReview, $conflict->status);
            $this->assertNotNull($conflict->scope_id);

            $kateryna = OwnerContextItem::query()->where('user_id', $user->id)->where('value', 'like', 'Kateryna Vale owns%')->firstOrFail();
            $this->assertSame(OwnerContextItemStatus::Accepted, $kateryna->status);

            $safe = app(OwnerContextItemService::class)->acceptSafe($user);
            $this->assertSame(0, $safe['accepted']);
            $this->assertSame(OwnerContextItemStatus::Candidate, $verify->fresh()->status);
            $this->assertStringNotContainsString('Restricted health detail', $logged);
            $this->assertStringNotContainsString('owner-context: structured', $logged);
        } finally {
            $this->cleanup($user);
        }
    }

    public function test_email_resolves_and_unknown_name_does_not_create_a_person(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $email = 'mira.'.Str::lower(Str::random(6)).'@example.test';
            $person = Person::factory()->create([
                'user_id' => $user->id,
                'display_name' => 'Mira Chen',
                'normalized_name' => ProjectNameNormalizer::normalize('Mira Chen'),
                'primary_email' => $email,
            ]);
            $before = Person::query()->where('user_id', $user->id)->count();
            $resolver = app(OwnerContextEntityResolver::class);

            $this->assertSame($person->id, $resolver->resolve($user, OwnerContextScopeType::Person, $email));
            $this->assertSame($person->id, $resolver->resolve($user, OwnerContextScopeType::Person, 'Mira Chen'));
            $this->assertNull($resolver->resolve($user, OwnerContextScopeType::Person, 'Unknown Quinn'));
            $this->assertSame($before, Person::query()->where('user_id', $user->id)->count());
        } finally {
            $this->cleanup($user);
        }
    }

    public function test_supersede_keeps_history_and_archive_keeps_items(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $service = app(OwnerContextItemService::class);
            $first = $service->addManual($user, $this->manual('Kateryna Vale owns Partnership.'));
            $second = $service->addManual($user, $this->manual('North lead owns Partnership.'));
            $this->assertSame(OwnerContextItemStatus::NeedsReview, $second->status);

            $this->actingAs($user)
                ->postJson(route('jarvis.owner-context.items.supersede', $second), ['previous_id' => $first->id])
                ->assertOk()
                ->assertJsonPath('supersedes_id', $first->id);

            $this->assertSame(OwnerContextItemStatus::Superseded, $first->fresh()->status);
            $this->assertNotNull($first->fresh()->effective_to);
            $this->assertSame(OwnerContextItemStatus::Accepted, $second->fresh()->status);

            $typo = $service->edit($user, $second->fresh(), ['value' => 'North lead owns Partnership']);
            $this->assertSame($second->id, $typo->id);

            $sourceId = $this->import($user, "- [FACT|identity|owner|normal|0.95] Default language is Ukrainian.\n");
            $this->actingAs($user)->postJson(route('jarvis.owner-context.archive', $sourceId))->assertOk();
            $this->assertSame(OwnerContextSourceStatus::Archived, OwnerContextSource::query()->findOrFail($sourceId)->status);
            $this->assertSame(OwnerContextItemStatus::Accepted, OwnerContextItem::query()->where('source_id', $sourceId)->firstOrFail()->status);
        } finally {
            $this->cleanup($user);
        }
    }

    public function test_retrieval_is_scoped_and_chat_omits_private_context(): void
    {
        $user = null;
        $other = null;

        try {
            $user = $this->owner();
            $service = app(OwnerContextItemService::class);
            $rule = $service->addManual($user, $this->manual('Keep no more than five company priorities.', 'ceo_operating_rule'));
            $private = $service->addManual($user, $this->manual('The owner avoids meetings before 09:00.', 'personal_constraint', 'private'));
            $service->accept($user, $private);
            $restricted = $service->addManual($user, $this->manual('Restricted health detail must stay out of general context.', 'other', 'restricted', 'fact'));
            $service->accept($user, $restricted);
            $historical = $service->addManual($user, $this->manual('The old office was in an unnamed city.', 'business_context', 'normal', 'historical', 'business'));
            $service->accept($user, $historical);
            $boundary = $service->addManual($user, $this->manual('Approved boundary is a twenty percent discount.', 'business_context', 'private', 'current', 'business'));
            $service->accept($user, $boundary);

            $retriever = app(OwnerContextRetriever::class);
            $overdue = $retriever->pack($user, new OwnerContextQuery(question: 'What is overdue?'));
            $this->assertStringContainsString('five company priorities', $overdue['prompt']);
            $this->assertStringNotContainsString('avoids meetings', $overdue['prompt']);
            $this->assertStringNotContainsString('Restricted health', $overdue['prompt']);
            $this->assertStringNotContainsString('unnamed city', $overdue['prompt']);
            $this->assertStringContainsString('outrank', $overdue['prompt']);

            $negotiation = $retriever->pack($user, new OwnerContextQuery(task: 'negotiation'));
            $this->assertStringContainsString('twenty percent discount', $negotiation['prompt']);
            $this->assertStringNotContainsString('unnamed city', $negotiation['prompt']);
            $this->assertLessThanOrEqual(6, count($overdue['ids']));

            $other = $this->owner();
            $this->actingAs($other)
                ->postJson(route('jarvis.owner-context.items.accept', $rule))
                ->assertNotFound();

            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            $inbound = Message::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'role' => MessageRole::User,
                'channel' => MessageChannel::Web,
                'body' => 'What is overdue?',
                'message_type' => MessageType::Text,
                'occurred_at' => now(),
            ]);
            $configuration = AiRoleSetting::query()->where('role_key', AiRoleKey::UserConversation->value)->firstOrFail();
            $context = app(ConversationContextBuilder::class)->build($user, $conversation, $configuration, $inbound);

            $this->assertStringContainsString('five company priorities', $context['system_prompt']);
            $this->assertStringNotContainsString('Restricted health', $context['system_prompt']);
            $this->assertStringNotContainsString('avoids meetings', $context['system_prompt']);
            $this->assertContains($rule->id, $context['diagnostics']['owner_context_ids']);
            $this->assertNotContains($restricted->id, $context['diagnostics']['owner_context_ids']);
        } finally {
            $this->cleanup($other);
            $this->cleanup($user);
        }
    }

    public function test_ai_failure_keeps_the_file_and_records_only_a_category(): void
    {
        $user = null;
        config(['owner_context.use_ai' => true]);
        $secret = 'Uniqueheadingtoken '.Str::random(8);
        $logged = '';
        Log::listen(function ($event) use (&$logged): void {
            $logged .= json_encode($event->context);
        });

        try {
            $user = $this->owner();
            $this->mock(AiChatGateway::class, function ($mock): void {
                $mock->shouldReceive('chat')->once()->andThrow(new RuntimeException('provider down'));
            });

            $sourceId = $this->import($user, "# {$secret}\n");
            $source = OwnerContextSource::query()->findOrFail($sourceId);

            $this->assertSame(OwnerContextSourceStatus::Failed, $source->status);
            $this->assertSame('extraction_failed', $source->metadata['error_category']);
            $this->assertTrue(Storage::disk('local')->exists((string) $source->storage_path));
            $this->assertStringNotContainsString($secret, $logged);
            $this->assertSame(0, OwnerContextItem::query()->where('source_id', $sourceId)->count());
        } finally {
            $this->cleanup($user);
        }
    }

    public function test_admin_list_hides_raw_item_text(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            app(OwnerContextItemService::class)->addManual($user, $this->manual('Restricted health detail must stay out of general context.', 'other', 'restricted', 'fact'));

            $this->actingAs($user)
                ->get(route('owner-context.index'))
                ->assertOk()
                ->assertDontSee('Restricted health detail');
        } finally {
            $this->cleanup($user);
        }
    }

    /**
     * @return array<string, string>
     */
    private function manual(
        string $value,
        string $category = 'role_context',
        string $sensitivity = 'normal',
        string $factClass = 'current',
        string $scope = 'owner',
    ): array {
        return [
            'value' => $value,
            'category' => $category,
            'fact_class' => $factClass,
            'scope_type' => $scope,
            'sensitivity' => $sensitivity,
        ];
    }

    private function import(User $user, string $contents): int
    {
        $response = $this->actingAs($user)->post(route('jarvis.owner-context.import'), [
            'name' => 'Synthetic '.Str::random(4),
            'source_date' => '2026-09-01',
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
