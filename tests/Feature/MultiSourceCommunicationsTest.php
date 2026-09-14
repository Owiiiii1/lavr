<?php

namespace Tests\Feature;

use App\Enums\CommitmentEvidenceType;
use App\Enums\CommitmentLifecycleStatus;
use App\Enums\CommitmentSourceType;
use App\Enums\ConversationKind;
use App\Enums\ConversationStatus;
use App\Enums\ExecutiveBriefType;
use App\Enums\IntegrationAccountStatus;
use App\Enums\MeetingAnalysisStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Enums\PersonIdentityType;
use App\Enums\PersonStatus;
use App\Enums\TelegramGroupStatus;
use App\Enums\UserRole;
use App\Models\Commitment;
use App\Models\Conversation;
use App\Models\IntegrationAccount;
use App\Models\Meeting;
use App\Models\MeetingAnalysis;
use App\Models\Message;
use App\Models\Person;
use App\Models\SourceItem;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Commitments\CommitmentPromotionService;
use App\Services\Conversations\ConversationService;
use App\Services\Directory\DirectoryService;
use App\Services\ExecutiveBrief\ExecutiveBriefCollector;
use App\Services\ExecutiveBrief\ExecutiveBriefItem;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Projects\ProjectService;
use App\Services\Sources\SourceIngestService;
use App\Services\Sources\TelegramGroupSummaryService;
use App\Services\Tools\Sources\SearchEmailTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class MultiSourceCommunicationsTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_known_email_creates_detected_commitment_and_unknown_does_not_create_person(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $account = $this->connectGoogle($owner);
            $person = $this->person($owner, 'Sergiy', 'sergiy@example.test');
            $ingest = app(SourceIngestService::class);

            $item = $ingest->ingestGmail($owner, $account, [
                'id' => 'mail-contract-1',
                'threadId' => 'th-contract',
                'from' => 'Sergiy <sergiy@example.test>',
                'subject' => 'Договор',
                'snippet' => 'Я пришлю договор завтра.',
                'date' => now(),
            ]);

            $this->assertSame($person->id, $item->person_id);
            $this->assertNotNull($item->integration_account_id);
            $this->assertSame('mail-contract-1', $item->external_id);

            $commitments = Commitment::query()->where('user_id', $owner->id)->get();
            $this->assertCount(1, $commitments);
            $this->assertSame(CommitmentLifecycleStatus::Detected, $commitments[0]->lifecycle_status);
            $this->assertSame(CommitmentSourceType::Email, $commitments[0]->source_type);

            $peopleBefore = Person::query()->where('user_id', $owner->id)->count();
            $unknown = $ingest->ingestGmail($owner, $account, [
                'id' => 'mail-unknown-1',
                'from' => 'Unknown <unknown-source@example.test>',
                'subject' => 'Hello',
                'snippet' => 'Я пришлю договор завтра.',
            ]);
            $this->assertNull($unknown->person_id);
            $this->assertTrue((bool) ($unknown->metadata['unresolved_identity'] ?? false));
            $this->assertSame($peopleBefore, Person::query()->where('user_id', $owner->id)->count());
            $this->assertSame(1, Commitment::query()->where('user_id', $owner->id)->count());
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_duplicate_gmail_ingest_does_not_duplicate_commitment(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $account = $this->connectGoogle($owner);
            $this->person($owner, 'Sergiy', 'sergiy@example.test');
            $ingest = app(SourceIngestService::class);
            $payload = [
                'id' => 'mail-dup-1',
                'from' => 'sergiy@example.test',
                'subject' => 'Budget',
                'snippet' => 'Я пришлю договор завтра.',
            ];

            $ingest->ingestGmail($owner, $account, $payload);
            $ingest->ingestGmail($owner, $account, $payload);

            $this->assertSame(1, SourceItem::query()->where('user_id', $owner->id)->where('external_id', 'mail-dup-1')->count());
            $this->assertSame(1, Commitment::query()->where('user_id', $owner->id)->count());
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_cross_source_story_keeps_one_commitment_and_maps_completion(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $account = $this->connectGoogle($owner);
            $person = $this->person($owner, 'Sergiy', 'sergiy@example.test');
            app(DirectoryService::class)->addIdentity($owner, $person, PersonIdentityType::TelegramUserId, '910777001');
            $project = app(ProjectService::class)->create($owner, 'Chicago');
            $meeting = Meeting::factory()->create([
                'user_id' => $owner->id,
                'project_id' => $project->id,
                'title' => 'Chicago budget',
            ]);
            $analysis = MeetingAnalysis::query()->create([
                'meeting_id' => $meeting->id,
                'version' => 1,
                'status' => MeetingAnalysisStatus::Completed,
                'result_json' => [
                    'commitments_detected' => [[
                        'person_name' => 'Sergiy',
                        'action' => 'Send final budget by Friday',
                        'expected_result' => 'Final budget',
                        'deadline_raw' => 'Friday',
                        'confidence' => 'high',
                        'evidence' => ['excerpt' => 'I will send the budget Friday', 'speaker' => 'Sergiy'],
                    ]],
                ],
            ]);
            $created = app(CommitmentPromotionService::class)->promoteFromMeetingAnalysis($owner, $meeting, $analysis, true)['created'][0];
            $this->assertSame($person->id, $created->person_id);
            $this->assertSame($project->id, $created->project_id);

            $group = $this->group($owner, 'Chicago Managers');
            $progress = $this->groupMessage($owner, $group, '910777001', 'sergiy', 'Budget almost ready.');
            app(SourceIngestService::class)->ingestTelegramMessage($owner, $group, $progress);

            $created->refresh();
            $this->assertSame(CommitmentLifecycleStatus::Detected, $created->lifecycle_status);
            $this->assertTrue($created->evidence()->where('evidence_type', CommitmentEvidenceType::Progress->value)->exists());
            $this->assertSame(1, Commitment::query()->where('user_id', $owner->id)->count());

            app(SourceIngestService::class)->ingestGmail($owner, $account, [
                'id' => 'mail-budget-final',
                'from' => 'Sergiy <sergiy@example.test>',
                'subject' => 'Final budget',
                'snippet' => 'Final budget attached.',
            ]);

            $created->refresh();
            $this->assertSame(CommitmentLifecycleStatus::LikelyDone, $created->lifecycle_status);
            $this->assertTrue($created->evidence()->where('evidence_type', CommitmentEvidenceType::Delivery->value)->exists());
            $this->assertSame(1, Commitment::query()->where('user_id', $owner->id)->count());
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_weak_completion_text_does_not_mark_likely_done(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $account = $this->connectGoogle($owner);
            $person = $this->person($owner, 'Sergiy', 'sergiy@example.test');
            $project = app(ProjectService::class)->create($owner, 'Milan');
            $meeting = Meeting::factory()->create([
                'user_id' => $owner->id,
                'project_id' => $project->id,
                'title' => 'Milan budget',
            ]);
            $analysis = MeetingAnalysis::query()->create([
                'meeting_id' => $meeting->id,
                'version' => 1,
                'status' => MeetingAnalysisStatus::Completed,
                'result_json' => [
                    'commitments_detected' => [[
                        'person_name' => 'Sergiy',
                        'action' => 'Send final budget',
                        'confidence' => 'high',
                        'evidence' => ['excerpt' => 'I will send the budget', 'speaker' => 'Sergiy'],
                    ]],
                ],
            ]);
            $commitment = app(CommitmentPromotionService::class)->promoteFromMeetingAnalysis($owner, $meeting, $analysis, true)['created'][0];

            app(SourceIngestService::class)->ingestGmail($owner, $account, [
                'id' => 'mail-weak',
                'from' => 'sergiy@example.test',
                'subject' => 'Budget',
                'snippet' => 'Budget almost ready, занимаюсь.',
            ]);

            $this->assertSame(CommitmentLifecycleStatus::Detected, $commitment->fresh()->lifecycle_status);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_telegram_group_summary_and_owner_command_is_not_an_employee_commitment(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $person = $this->person($owner, 'Maria', 'maria@example.test');
            app(DirectoryService::class)->addIdentity($owner, $person, PersonIdentityType::TelegramUserId, '910777002');
            $group = $this->group($owner, 'Chicago Managers');
            $ingest = app(SourceIngestService::class);

            $work = $this->groupMessage($owner, $group, '910777002', 'maria', 'Сделаю презентацию к среде.');
            $ingest->ingestTelegramMessage($owner, $group, $work);
            $this->assertSame(1, Commitment::query()->where('user_id', $owner->id)->where('person_id', $person->id)->count());

            $personal = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $command = Message::query()->create([
                'conversation_id' => $personal->id,
                'user_id' => $owner->id,
                'role' => MessageRole::User,
                'channel' => MessageChannel::Telegram,
                'body' => 'Сделаю презентацию к среде.',
                'message_type' => MessageType::Text,
                'occurred_at' => now(),
            ]);
            $ingest->ingestTelegramMessage($owner, $group, $command->load('conversation'), true);
            $this->assertSame(1, Commitment::query()->where('user_id', $owner->id)->count());

            $summary = app(TelegramGroupSummaryService::class)->summarize($owner, $group);
            $this->assertSame('Chicago Managers', $summary['title']);
            $this->assertNotEmpty($summary['updates']);
            $this->assertNotEmpty($summary['commitments']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_executive_brief_aggregates_mailboxes_and_stays_partial_when_one_is_blocked(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner, 'google-sub-ok', 'ok@example.test', 'OK');
            $blocked = $this->connectGoogle($owner, 'google-sub-bad', 'bad@example.test', 'Bad');
            $collector = new ExecutiveBriefCollector(
                app(IntegrationAccountService::class),
                null,
                new class($blocked->id)
                {
                    public function __construct(private readonly int $blockedId) {}

                    public function searchMessages(IntegrationAccount $account, string $query, array $options = []): array
                    {
                        if ($account->id === $this->blockedId) {
                            throw new \RuntimeException('timeout');
                        }

                        return [
                            'messages' => [
                                ['id' => 'm1', 'from' => 'Sony', 'subject' => 'Urgent budget', 'snippet' => 'deadline tomorrow', 'body' => 'ok'],
                            ],
                        ];
                    }
                },
            );

            $collected = $collector->collect($owner, ExecutiveBriefType::Morning, CarbonImmutable::now('UTC'), 'Europe/Rome');
            $types = array_map(static fn (ExecutiveBriefItem $item): string => $item->type, $collected['items']);
            $this->assertContains('email_important', $types);
            $this->assertTrue(
                collect($collected['snapshot']['errors'] ?? [])->contains(
                    fn ($error): bool => str_contains((string) $error, 'Bad'),
                ),
            );
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_search_email_unknown_is_not_empty(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $result = app(ToolRegistry::class)->execute(
                new ToolCall('n1', SearchEmailTool::NAME, ['query' => 'in:inbox']),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertTrue($result->success);
            $this->assertSame('unknown', $result->payload['semantics']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    private function connectGoogle(
        User $owner,
        string $sub = 'google-sub-mail',
        string $email = 'chicago@example.test',
        string $label = 'Chicago',
    ): IntegrationAccount {
        $accounts = app(IntegrationAccountService::class);
        $account = $accounts->upsertAccount(
            $owner,
            'google',
            $sub,
            $email,
            IntegrationAccountStatus::Connected,
            [
                'email',
                'openid',
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/calendar',
            ],
            null,
            $label,
        );
        $accounts->setCredentials($account, [
            'access_token' => 'token-'.$sub,
            'refresh_token' => 'refresh-'.$sub,
            'expires_at' => now()->addHour()->toIso8601String(),
            'token_type' => 'Bearer',
        ]);
        $accounts->markConnected($account);

        return $account->fresh() ?? $account;
    }

    private function person(User $owner, string $name, string $email): Person
    {
        $directory = app(DirectoryService::class);

        return $directory->createPerson($owner, [
            'first_name' => $name,
            'display_name' => $name,
            'primary_email' => $email,
            'status' => PersonStatus::Active->value,
        ]);
    }

    private function group(User $owner, string $title): TelegramGroup
    {
        $conversation = Conversation::query()->create([
            'user_id' => $owner->id,
            'kind' => ConversationKind::Group,
            'title' => $title,
            'status' => ConversationStatus::Active,
            'last_activity_at' => now(),
        ]);

        return TelegramGroup::query()->create([
            'telegram_chat_id' => '-91'.random_int(1000000, 9999999),
            'conversation_id' => $conversation->id,
            'title' => $title,
            'chat_type' => 'supergroup',
            'status' => TelegramGroupStatus::Connected,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'last_message_at' => now(),
            'settings' => ['monitoring_enabled' => true],
            'message_count' => 0,
        ]);
    }

    private function groupMessage(User $owner, TelegramGroup $group, string $telegramUserId, string $username, string $body): Message
    {
        return Message::query()->create([
            'conversation_id' => $group->conversation_id,
            'user_id' => $owner->id,
            'telegram_group_id' => $group->id,
            'role' => MessageRole::User,
            'channel' => MessageChannel::Telegram,
            'body' => $body,
            'message_type' => MessageType::Text,
            'channel_message_id' => (string) random_int(1000, 999999),
            'sender_external_id' => $telegramUserId,
            'sender_username' => $username,
            'sender_name' => $username,
            'occurred_at' => now(),
        ])->load('conversation');
    }

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
