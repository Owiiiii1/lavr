<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\IntegrationAccountStatus;
use App\Enums\MessageChannel;
use App\Enums\UserRole;
use App\Models\GoogleOAuthSetting;
use App\Models\IntegrationAccount;
use App\Models\ToolConfirmation;
use App\Models\ToolExecutionLog;
use App\Models\User;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatResponse;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Conversations\ChannelContext;
use App\Services\Conversations\ConversationService;
use App\Services\Conversations\ConversationTurnService;
use App\Services\Integrations\Google\GmailMimeParser;
use App\Services\Integrations\Google\GoogleCredentialService;
use App\Services\Integrations\Google\GoogleGmailService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Tools\CancelToolActionTool;
use App\Services\Tools\ConfirmToolActionTool;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\Google\CreateCalendarEventTool;
use App\Services\Tools\Google\CreateGmailDraftTool;
use App\Services\Tools\Google\GetGmailMessageTool;
use App\Services\Tools\Google\GetGmailThreadTool;
use App\Services\Tools\Google\ListGmailLabelsTool;
use App\Services\Tools\Google\ListGmailMessagesTool;
use App\Services\Tools\Google\ModifyGmailLabelsTool;
use App\Services\Tools\Google\SearchGmailTool;
use App\Services\Tools\Google\SendGmailMessageTool;
use App\Services\Tools\SearchConversationHistoryTool;
use App\Services\Tools\Sources\SearchEmailTool;
use App\Services\Tools\ToolConfirmationService;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\Facades\Http;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\RestoresAiRoleSettings;
use Tests\Support\RestoresGoogleOAuthSettings;
use Tests\TestCase;

class GoogleGmailTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresAiRoleSettings;
    use RestoresGoogleOAuthSettings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshotGoogleOAuthSettings();
        GoogleOAuthSetting::query()->delete();
    }

    protected function tearDown(): void
    {
        $this->restoreGoogleOAuthSettings();

        parent::tearDown();
    }

    public function test_owner_receives_gmail_tools_and_user_does_not(): void
    {
        $owner = null;
        $user = null;

        try {
            $owner = $this->temporaryOwner();
            $user = $this->createTemporaryUser();
            $ownerChat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $userChat = app(ConversationService::class)->createPersonal($user, 'Основной');
            $registry = app(ToolRegistry::class);

            $ownerTools = array_map(static fn ($tool) => $tool->name, $registry->definitionsFor(new ToolExecutionContext($owner, $ownerChat)));
            $userTools = array_map(static fn ($tool) => $tool->name, $registry->definitionsFor(new ToolExecutionContext($user, $userChat)));

            foreach ($this->gmailToolNames() as $name) {
                $this->assertContains($name, $ownerTools);
                $this->assertNotContains($name, $userTools);
            }

            $this->assertContains(CreateReminderTool::NAME, $userTools);
            $this->assertContains(SearchConversationHistoryTool::NAME, $userTools);
            $this->assertNotContains(SearchEmailTool::NAME, $userTools);

            $forged = $registry->execute(
                new ToolCall('f1', SearchGmailTool::NAME, ['query' => 'from:someone']),
                new ToolExecutionContext($user, $userChat, explicitUserCommand: true),
            );
            $this->assertSame('tool_not_available', $forged->payload['error']);
            Http::assertNothingSent();
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_disconnected_owner_does_not_call_gmail(): void
    {
        $owner = null;

        try {
            Http::fake();
            $owner = $this->temporaryOwner();
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('d1', SearchGmailTool::NAME, ['query' => 'in:inbox']),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertSame('google_not_connected', $result->payload['error']);
            Http::assertNothingSent();
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_identity_or_calendar_only_requires_gmail_scope(): void
    {
        $owner = null;

        try {
            Http::fake();
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner, calendar: true, gmail: false);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');

            foreach ([
                [SearchGmailTool::NAME, ['query' => 'from:x']],
                [CreateGmailDraftTool::NAME, ['to' => ['a@example.test'], 'subject' => 'S', 'body' => 'B']],
                [SendGmailMessageTool::NAME, ['to' => ['a@example.test'], 'subject' => 'S', 'body' => 'B']],
                [ModifyGmailLabelsTool::NAME, ['message_id' => 'm1', 'remove_label_ids' => ['UNREAD']]],
            ] as [$name, $args]) {
                $result = app(ToolRegistry::class)->execute(
                    new ToolCall('s-'.$name, $name, $args),
                    new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
                );
                $this->assertSame('gmail_scope_required', $result->payload['error'], $name);
            }

            Http::assertNothingSent();
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_incremental_gmail_oauth_merges_scopes_and_keeps_refresh_token(): void
    {
        $owner = null;

        try {
            $this->configureGoogle();
            $owner = $this->temporaryOwner();
            $account = $this->connectGoogle($owner, calendar: true, gmail: false);

            $response = $this->actingAs($owner)->get(route('integrations.google.connect', ['intent' => 'gmail']));
            $response->assertRedirect();
            $location = $response->headers->get('Location');
            parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
            $this->assertStringContainsString('openid', (string) ($query['scope'] ?? ''));
            $this->assertStringContainsString('https://www.googleapis.com/auth/gmail.readonly', (string) ($query['scope'] ?? ''));
            $this->assertStringContainsString('https://www.googleapis.com/auth/gmail.compose', (string) ($query['scope'] ?? ''));
            $this->assertStringContainsString('https://www.googleapis.com/auth/gmail.modify', (string) ($query['scope'] ?? ''));
            $this->assertStringNotContainsString('mail.google.com', (string) ($query['scope'] ?? ''));
            $this->assertSame('true', $query['include_granted_scopes'] ?? null);

            $before = $this->actingAs($owner)->get(route('settings.index', ['tab' => 'integrations']));
            $this->assertStringContainsString('Enable Gmail', $before->getContent());
            $this->assertStringContainsString('"key":"gmail"', $before->getContent());

            Http::fake([
                'https://oauth2.googleapis.com/token' => Http::response([
                    'access_token' => 'synthetic-access-token-gmail',
                    'expires_in' => 3600,
                    'token_type' => 'Bearer',
                    'scope' => implode(' ', [
                        'https://www.googleapis.com/auth/gmail.readonly',
                        'https://www.googleapis.com/auth/gmail.compose',
                        'https://www.googleapis.com/auth/gmail.modify',
                    ]),
                ], 200),
                'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                    'sub' => 'google-sub-1',
                    'email' => 'owner@example.test',
                    'email_verified' => true,
                ], 200),
            ]);

            $state = (string) session('google_oauth_state')['state'];
            $this->actingAs($owner)->get(route('integrations.google.callback', [
                'state' => $state,
                'code' => 'synthetic-gmail-code',
            ]))->assertRedirect(route('settings.index', ['tab' => 'integrations']));

            $account->refresh();
            $this->assertContains('openid', $account->scopes ?? []);
            $this->assertContains('https://www.googleapis.com/auth/calendar', $account->scopes ?? []);
            $this->assertContains('https://www.googleapis.com/auth/gmail.readonly', $account->scopes ?? []);
            $envelope = app(IntegrationAccountService::class)->getCredentials($account);
            $this->assertSame('synthetic-refresh-token', $envelope['refresh_token']);
            $this->assertSame('synthetic-access-token-gmail', $envelope['access_token']);

            $page = $this->actingAs($owner)->get(route('settings.index', ['tab' => 'integrations']));
            $this->assertStringContainsString('"key":"gmail"', $page->getContent());
            $this->assertStringContainsString('"state":"enabled"', $page->getContent());
            $this->assertStringNotContainsString('Enable Gmail', $page->getContent());
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_search_is_normalized_and_bounded(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            config(['google_gmail.max_search_results' => 1]);

            Http::fake(function ($request) {
                if ($this->isGmailMessageList($request)) {
                    $this->assertStringContainsString('from:marco', (string) ($request->data()['q'] ?? $request->url()));

                    return Http::response([
                        'messages' => [['id' => 'm1'], ['id' => 'm2']],
                        'nextPageToken' => 'page-2',
                    ], 200);
                }

                if ($this->gmailMessageId($request) === 'm1') {
                    return Http::response($this->messageResource('m1', 'Contract', 'Marco <marco@example.test>', 'Need the contract', ['UNREAD', 'INBOX']), 200);
                }

                return Http::response(['error' => ['status' => 'NOT_FOUND']], 404);
            });

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('q1', SearchGmailTool::NAME, ['query' => 'from:marco', 'max_results' => 50]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertTrue($result->success);
            $this->assertTrue($result->payload['truncated']);
            $this->assertTrue($result->payload['next_page_available']);
            $this->assertSame(1, $result->payload['result_count']);
            $this->assertSame('Contract', $result->payload['messages'][0]['subject']);
            $this->assertSame('Marco <marco@example.test>', $result->payload['messages'][0]['from']);
            $this->assertTrue($result->payload['messages'][0]['unread']);
            $this->assertArrayNotHasKey('body', $result->payload['messages'][0]);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_list_unread_inbox_uses_gmail_query(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $seen = [];
            Http::fake(function ($request) use (&$seen) {
                if ($this->isGmailMessageList($request)) {
                    $seen[] = (string) ($request->data()['q'] ?? $request->url());

                    return Http::response(['messages' => []], 200);
                }

                return Http::response(['error' => ['status' => 'NOT_FOUND']], 404);
            });

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('u1', ListGmailMessagesTool::NAME, ['unread' => true]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertTrue($result->success);
            $this->assertSame(0, $result->payload['unread_count']);
            $this->assertNotEmpty($seen);
            $this->assertStringContainsString('in:inbox', $seen[0]);
            $this->assertStringContainsString('is:unread', $seen[0]);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_mime_parser_handles_common_payloads_and_truncation(): void
    {
        $parser = app(GmailMimeParser::class);
        config(['google_gmail.max_body_chars' => 8]);

        $plain = $parser->parseMessage($this->messageResource('p1', 'Plain', 'a@example.test', 'Hello world'));
        $this->assertSame('Hello wo', $plain['body']);
        $this->assertTrue($plain['truncated']);

        config(['google_gmail.max_body_chars' => 12000]);

        $html = $parser->parseMessage([
            'id' => 'h1',
            'threadId' => 't1',
            'snippet' => 'Hi',
            'labelIds' => [],
            'payload' => [
                'mimeType' => 'text/html',
                'headers' => $this->headers('Html', 'a@example.test'),
                'body' => ['data' => $this->b64url('<p>Hello&nbsp;<b>there</b></p>')],
            ],
        ]);
        $this->assertSame('Hello there', $html['body']);
        $this->assertTrue($html['body_from_html']);

        $alt = $parser->parseMessage([
            'id' => 'a1',
            'threadId' => 't1',
            'snippet' => 'Alt',
            'labelIds' => [],
            'payload' => [
                'mimeType' => 'multipart/alternative',
                'headers' => $this->headers('Alt', 'a@example.test'),
                'parts' => [
                    [
                        'mimeType' => 'text/plain',
                        'body' => ['data' => $this->b64url('Plain wins')],
                    ],
                    [
                        'mimeType' => 'text/html',
                        'body' => ['data' => $this->b64url('<p>HTML ignored</p>')],
                    ],
                ],
            ],
        ]);
        $this->assertSame('Plain wins', $alt['body']);
        $this->assertFalse($alt['body_from_html']);

        $nested = $parser->parseMessage([
            'id' => 'n1',
            'threadId' => 't1',
            'snippet' => 'Nested',
            'labelIds' => ['INBOX'],
            'payload' => [
                'mimeType' => 'multipart/mixed',
                'headers' => $this->headers('Nested', 'a@example.test'),
                'parts' => [
                    [
                        'mimeType' => 'multipart/alternative',
                        'parts' => [
                            ['mimeType' => 'text/plain', 'body' => ['data' => $this->b64url('Nested plain')]],
                            ['mimeType' => 'text/html', 'body' => ['data' => $this->b64url('<p>Nested html</p>')]],
                        ],
                    ],
                    [
                        'filename' => 'brief.pdf',
                        'mimeType' => 'application/pdf',
                        'body' => ['attachmentId' => 'att-1', 'size' => 2048],
                    ],
                ],
            ],
        ]);
        $this->assertSame('Nested plain', $nested['body']);
        $this->assertSame('brief.pdf', $nested['attachments'][0]['filename']);
        $this->assertSame('application/pdf', $nested['attachments'][0]['mime_type']);
        $this->assertSame(2048, $nested['attachments'][0]['size']);
        $this->assertSame('att-1', $nested['attachments'][0]['attachment_id']);
        $this->assertArrayNotHasKey('data', $nested['attachments'][0]);
    }

    public function test_thread_is_chronological_and_char_bounded(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            config(['google_gmail.max_thread_messages' => 2, 'google_gmail.max_total_thread_chars' => 12]);

            Http::fake([
                'https://gmail.googleapis.com/gmail/v1/users/me/threads/*' => Http::response([
                    'id' => 'th1',
                    'messages' => [
                        $this->messageResource('m2', 'Re: Topic', 'b@example.test', 'Second body', [], 'th1', 2000),
                        $this->messageResource('m1', 'Topic', 'a@example.test', 'First body', [], 'th1', 1000),
                    ],
                ], 200),
            ]);

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('th1', GetGmailThreadTool::NAME, ['thread_id' => 'th1', 'max_messages' => 9]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertTrue($result->success);
            $this->assertSame('m1', $result->payload['messages'][0]['id']);
            $this->assertTrue($result->payload['truncated']);
            $this->assertLessThanOrEqual(12, mb_strlen((string) $result->payload['messages'][0]['body']));
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_get_message_returns_normalized_body_and_attachment_metadata(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            Http::fake([
                'https://gmail.googleapis.com/gmail/v1/users/me/messages/*' => Http::response([
                    'id' => 'msg-1',
                    'threadId' => 'th-1',
                    'snippet' => 'Need a review',
                    'labelIds' => ['INBOX', 'UNREAD'],
                    'payload' => [
                        'mimeType' => 'multipart/mixed',
                        'headers' => $this->headers('Need a review', 'Client <client@example.test>', 'owner@example.test'),
                        'parts' => [
                            ['mimeType' => 'text/plain', 'body' => ['data' => $this->b64url('Please review the file.')]],
                            [
                                'filename' => 'spec.pdf',
                                'mimeType' => 'application/pdf',
                                'body' => ['attachmentId' => 'att-9', 'size' => 99],
                            ],
                        ],
                    ],
                ], 200),
            ]);

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('g1', GetGmailMessageTool::NAME, ['message_id' => 'msg-1']),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertTrue($result->success);
            $this->assertSame('Please review the file.', $result->payload['message']['body']);
            $this->assertSame('spec.pdf', $result->payload['message']['attachments'][0]['filename']);
            $this->assertTrue($result->payload['message']['unread']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_list_labels_is_bounded(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            config(['google_gmail.max_labels' => 1]);
            Http::fake([
                'https://gmail.googleapis.com/gmail/v1/users/me/labels' => Http::response([
                    'labels' => [
                        ['id' => 'INBOX', 'name' => 'INBOX', 'type' => 'system', 'messagesTotal' => 3],
                        ['id' => 'Label_1', 'name' => 'Project', 'type' => 'user'],
                    ],
                ], 200),
            ]);

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('lb1', ListGmailLabelsTool::NAME, []),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertTrue($result->success);
            $this->assertTrue($result->payload['truncated']);
            $this->assertSame('INBOX', $result->payload['labels'][0]['id']);
            $this->assertSame(3, $result->payload['labels'][0]['messages_total']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_explicit_draft_creates_and_model_proposed_requires_confirmation(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $sends = 0;
            $drafts = 0;
            Http::fake(function ($request) use (&$sends, &$drafts) {
                if (str_contains($request->url(), '/messages/send')) {
                    $sends++;

                    return Http::response(['id' => 'sent'], 200);
                }
                if (str_contains($request->url(), '/drafts')) {
                    $drafts++;

                    return Http::response(['id' => 'dr1', 'message' => ['id' => 'm-dr', 'threadId' => 'th-dr']], 200);
                }

                return Http::response(['error' => ['status' => 'NOT_FOUND']], 404);
            });

            $explicit = app(ToolRegistry::class)->execute(
                new ToolCall('dr1', CreateGmailDraftTool::NAME, [
                    'to' => ['anna@example.test'],
                    'subject' => 'Agenda',
                    'body' => 'Draft body',
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $this->assertTrue($explicit->success);
            $this->assertSame('dr1', $explicit->payload['draft_id']);
            $this->assertSame(1, $drafts);
            $this->assertSame(0, $sends);

            $proposed = app(ToolRegistry::class)->execute(
                new ToolCall('dr2', CreateGmailDraftTool::NAME, [
                    'to' => ['anna@example.test'],
                    'subject' => 'Agenda',
                    'body' => 'Another draft',
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: false),
            );
            $this->assertSame('confirmation_required', $proposed->payload['error']);
            $this->assertSame(1, $drafts);
            $this->assertSame(0, $sends);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_send_always_requires_one_time_confirmation(): void
    {
        $owner = null;
        $other = null;

        try {
            $owner = $this->temporaryOwner();
            $other = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $otherChat = app(ConversationService::class)->createPersonal($other, 'Основной');
            $sends = 0;
            Http::fake(function ($request) use (&$sends) {
                if (str_contains($request->url(), '/messages/send')) {
                    $sends++;

                    return Http::response(['id' => 'sent-1', 'threadId' => 'th-s'], 200);
                }

                return Http::response(['error' => ['status' => 'NOT_FOUND']], 404);
            });

            $initial = app(ToolRegistry::class)->execute(
                new ToolCall('sd1', SendGmailMessageTool::NAME, [
                    'to' => ['anna@example.test'],
                    'subject' => 'Hello',
                    'body' => 'Visible send',
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $this->assertSame('confirmation_required', $initial->payload['error']);
            $this->assertSame(0, $sends);
            $this->assertSame(['anna@example.test'], $initial->payload['preview']['to']);
            $this->assertSame('Hello', $initial->payload['preview']['subject']);
            $this->assertStringContainsString('Visible send', (string) $initial->payload['preview']['body_preview']);

            $confirmation = ToolConfirmation::query()->where('user_id', $owner->id)->first();
            $this->assertNotNull($confirmation);

            $otherConfirm = app(ToolRegistry::class)->execute(
                new ToolCall('xc1', ConfirmToolActionTool::NAME, ['confirmation_id' => $confirmation->public_id]),
                new ToolExecutionContext($other, $otherChat, explicitUserCommand: true, confirmationIntent: 'confirm'),
            );
            $this->assertSame('tool_not_available', $otherConfirm->payload['error']);
            $this->assertSame(0, $sends);

            $executed = app(ToolConfirmationService::class)->executeConfirmed(
                $confirmation,
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true, confirmationIntent: 'confirm'),
                app(ToolRegistry::class),
            );
            $this->assertTrue($executed->success);
            $this->assertSame(1, $sends);

            $repeat = app(ToolConfirmationService::class)->executeConfirmed(
                $confirmation->fresh(),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true, confirmationIntent: 'confirm'),
                app(ToolRegistry::class),
            );
            $this->assertSame('confirmation_already_executed', $repeat->payload['error']);
            $this->assertSame(1, $sends);
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($other);
        }
    }

    public function test_cancelled_and_expired_send_do_not_call_gmail(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            Http::fake();

            $expired = app(ToolConfirmationService::class)->createPending(
                $owner,
                $chat,
                SendGmailMessageTool::NAME,
                ['to' => ['anna@example.test'], 'subject' => 'X', 'body' => 'Y'],
                'exp-1',
            );
            $expired->forceFill(['expires_at' => now()->subMinute()])->save();
            $expiredResult = app(ToolConfirmationService::class)->executeConfirmed(
                $expired,
                new ToolExecutionContext($owner, $chat, confirmationIntent: 'confirm'),
                app(ToolRegistry::class),
            );
            $this->assertSame('confirmation_expired', $expiredResult->payload['error']);

            $pendingResult = app(ToolRegistry::class)->execute(
                new ToolCall('sd2', SendGmailMessageTool::NAME, [
                    'to' => ['anna@example.test'],
                    'subject' => 'X',
                    'body' => 'Y',
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $pending = ToolConfirmation::query()->where('public_id', $pendingResult->payload['confirmation_id'])->first();
            $cancel = app(ToolRegistry::class)->execute(
                new ToolCall('cn1', CancelToolActionTool::NAME, ['confirmation_id' => $pending->public_id]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true, confirmationIntent: 'cancel'),
            );
            $this->assertTrue($cancel->success);
            $afterCancel = app(ToolConfirmationService::class)->executeConfirmed(
                $pending->fresh(),
                new ToolExecutionContext($owner, $chat, confirmationIntent: 'confirm'),
                app(ToolRegistry::class),
            );
            $this->assertSame('confirmation_cancelled', $afterCancel->payload['error']);
            Http::assertNothingSent();
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_reply_sets_thread_headers(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $raw = null;
            Http::fake(function ($request) use (&$raw) {
                if ($request->method() === 'GET' && str_contains($request->url(), '/messages/orig-1')) {
                    $resource = $this->messageResource('orig-1', 'Contract', 'Marco <marco@example.test>', 'Please review');
                    $resource['payload']['headers'][] = ['name' => 'Message-ID', 'value' => '<orig@mail>'];
                    $resource['payload']['headers'][] = ['name' => 'References', 'value' => '<root@mail>'];

                    return Http::response($resource, 200);
                }

                if (str_contains($request->url(), '/messages/send')) {
                    $raw = $request->data()['raw'] ?? null;
                    $this->assertSame('th-1', $request->data()['threadId'] ?? null);

                    return Http::response(['id' => 'sent-r', 'threadId' => 'th-1'], 200);
                }

                return Http::response(['error' => ['status' => 'NOT_FOUND']], 404);
            });

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('rp1', SendGmailMessageTool::NAME, [
                    'reply_to_message_id' => 'orig-1',
                    'body' => 'Meeting moved.',
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true, bypassConfirmation: true),
            );

            $this->assertTrue($result->success);
            $decoded = $this->decodeRaw((string) $raw);
            $this->assertStringContainsString('In-Reply-To: <orig@mail>', $decoded);
            $this->assertStringContainsString('References: <root@mail> <orig@mail>', $decoded);
            $this->assertStringContainsString('To: marco@example.test', $decoded);
            $this->assertStringContainsString('Subject: Re: Contract', $decoded);
            $this->assertStringContainsString('Meeting moved.', $decoded);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_label_modify_mark_read_and_archive(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $bodies = [];
            Http::fake(function ($request) use (&$bodies) {
                if (str_contains($request->url(), '/modify')) {
                    $bodies[] = $request->data();

                    return Http::response([
                        'id' => 'm1',
                        'threadId' => 'th1',
                        'labelIds' => ['INBOX'],
                    ], 200);
                }

                return Http::response(['error' => ['status' => 'NOT_FOUND']], 404);
            });

            $read = app(ToolRegistry::class)->execute(
                new ToolCall('mr1', ModifyGmailLabelsTool::NAME, [
                    'message_id' => 'm1',
                    'remove_label_ids' => ['UNREAD'],
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $archive = app(ToolRegistry::class)->execute(
                new ToolCall('ar1', ModifyGmailLabelsTool::NAME, [
                    'message_id' => 'm1',
                    'remove_label_ids' => ['INBOX'],
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertTrue($read->success);
            $this->assertTrue($archive->success);
            $this->assertSame(['UNREAD'], $bodies[0]['removeLabelIds']);
            $this->assertSame(['INBOX'], $bodies[1]['removeLabelIds']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_validation_rejects_unsafe_outbound_without_http(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            Http::fake();
            $registry = app(ToolRegistry::class);
            $context = new ToolExecutionContext($owner, $chat, explicitUserCommand: true);

            $invalid = $registry->execute(new ToolCall('v1', CreateGmailDraftTool::NAME, [
                'to' => ['not-an-email'],
                'subject' => 'S',
                'body' => 'B',
            ]), $context);
            $this->assertSame('gmail_invalid_recipient', $invalid->payload['error']);

            $crlf = $registry->execute(new ToolCall('v2', CreateGmailDraftTool::NAME, [
                'to' => ["anna@example.test\r\nBcc: hidden@example.test"],
                'subject' => 'S',
                'body' => 'B',
            ]), $context);
            $this->assertSame('invalid_arguments', $crlf->payload['error']);

            $empty = $registry->execute(new ToolCall('v3', CreateGmailDraftTool::NAME, [
                'subject' => 'S',
                'body' => 'B',
            ]), $context);
            $this->assertSame('invalid_arguments', $empty->payload['error']);

            config(['google_gmail.max_recipients' => 1]);
            $many = $registry->execute(new ToolCall('v4', CreateGmailDraftTool::NAME, [
                'to' => ['a@example.test', 'b@example.test'],
                'subject' => 'S',
                'body' => 'B',
            ]), $context);
            $this->assertSame('invalid_arguments', $many->payload['error']);

            config(['google_gmail.max_subject_chars' => 3, 'google_gmail.max_recipients' => 20]);
            $long = $registry->execute(new ToolCall('v5', CreateGmailDraftTool::NAME, [
                'to' => ['a@example.test'],
                'subject' => 'Too long',
                'body' => 'B',
            ]), $context);
            $this->assertSame('invalid_arguments', $long->payload['error']);

            Http::assertNothingSent();
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_write_http_is_not_retried(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $hits = 0;
            Http::fake(function ($request) use (&$hits) {
                if (str_contains($request->url(), '/drafts')) {
                    $hits++;

                    return Http::response(['error' => ['status' => 'UNAVAILABLE']], 500);
                }

                return Http::response(['error' => ['status' => 'NOT_FOUND']], 404);
            });

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('nr1', CreateGmailDraftTool::NAME, [
                    'to' => ['anna@example.test'],
                    'subject' => 'S',
                    'body' => 'B',
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertSame('gmail_unavailable', $result->payload['error']);
            $this->assertSame(1, $hits);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_logs_do_not_contain_email_content_or_tokens(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            Http::fake([
                'https://gmail.googleapis.com/gmail/v1/users/me/messages*' => function ($request) {
                    if ($this->isGmailMessageList($request)) {
                        return Http::response(['messages' => [['id' => 'm1']]], 200);
                    }

                    return Http::response($this->messageResource('m1', 'Secret subject', 'hidden@example.test', 'Secret body text'), 200);
                },
            ]);

            app(ToolRegistry::class)->execute(
                new ToolCall('sec1', SearchGmailTool::NAME, ['query' => 'from:hidden@example.test']),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $log = ToolExecutionLog::query()->where('user_id', $owner->id)->latest('id')->first();
            $encoded = json_encode($log?->toArray());
            $this->assertStringNotContainsString('synthetic-access-token', (string) $encoded);
            $this->assertStringNotContainsString('synthetic-refresh-token', (string) $encoded);
            $this->assertStringNotContainsString('Secret subject', (string) $encoded);
            $this->assertStringNotContainsString('Secret body text', (string) $encoded);
            $this->assertStringNotContainsString('hidden@example.test', (string) $encoded);
            $this->assertSame(1, $log?->metadata['result_count'] ?? null);
            $this->assertArrayHasKey('truncated', $log?->metadata ?? []);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_expired_token_refreshes_through_credential_service(): void
    {
        $owner = null;

        try {
            $this->configureGoogle();
            $owner = $this->temporaryOwner();
            $account = $this->connectGoogle($owner);
            app(IntegrationAccountService::class)->setCredentials($account, [
                'access_token' => 'expired-access',
                'refresh_token' => 'synthetic-refresh-token',
                'expires_at' => now()->subMinute()->toIso8601String(),
            ]);

            Http::fake([
                'https://oauth2.googleapis.com/token' => Http::response([
                    'access_token' => 'refreshed-access',
                    'expires_in' => 3600,
                    'token_type' => 'Bearer',
                ], 200),
                'https://gmail.googleapis.com/gmail/v1/users/me/labels' => function ($request) {
                    $this->assertSame('Bearer refreshed-access', $request->header('Authorization')[0] ?? null);

                    return Http::response(['labels' => [['id' => 'INBOX', 'name' => 'INBOX', 'type' => 'system']]], 200);
                },
            ]);

            $result = app(GoogleGmailService::class)->listLabels($account->fresh());
            $this->assertSame('INBOX', $result['labels'][0]['id']);
            Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/token'));
            $this->assertSame('refreshed-access', app(GoogleCredentialService::class)->getValidAccessToken($account->fresh()));
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_gmail_to_calendar_multi_tool_loop(): void
    {
        $owner = null;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerConversation);
            $fake = new FakeAiChatGateway;
            $this->app->forgetInstance(AiChatGateway::class);
            $this->app->instance(AiChatGateway::class, $fake);

            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $conversation = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $created = 0;
            Http::fake(function ($request) use (&$created) {
                $url = $request->url();
                if (str_contains($url, 'gmail.googleapis.com') && $request->method() === 'GET') {
                    if ($this->isGmailMessageList($request)) {
                        return Http::response(['messages' => [['id' => 'mail-1']]], 200);
                    }

                    if ($this->gmailMessageId($request) !== null) {
                        return Http::response($this->messageResource((string) $this->gmailMessageId($request), 'Meet Friday', 'Marco <marco@example.test>', 'Friday 10:00'), 200);
                    }
                }

                if (str_contains($url, 'googleapis.com/calendar') && $request->method() === 'POST' && str_contains($url, '/events')) {
                    $created++;

                    return Http::response([
                        'id' => $request->data()['id'] ?? 'cal-1',
                        'summary' => 'From mail',
                        'start' => ['dateTime' => '2026-09-11T10:00:00', 'timeZone' => 'Europe/Rome'],
                        'end' => ['dateTime' => '2026-09-11T11:00:00', 'timeZone' => 'Europe/Rome'],
                    ], 200);
                }

                return Http::response(['error' => ['status' => 'NOT_FOUND']], 404);
            });

            $fake->script[] = new AiChatResponse(
                text: '',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'tool_calls',
                toolCalls: [new ToolCall('g1', SearchGmailTool::NAME, ['query' => 'from:marco'])],
            );
            $fake->script[] = new AiChatResponse(
                text: '',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'tool_calls',
                toolCalls: [new ToolCall('g2', GetGmailMessageTool::NAME, ['message_id' => 'mail-1'])],
            );
            $fake->script[] = new AiChatResponse(
                text: '',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'tool_calls',
                toolCalls: [new ToolCall('g3', CreateCalendarEventTool::NAME, [
                    'title' => 'From mail',
                    'start' => '2026-09-11T10:00:00',
                    'end' => '2026-09-11T11:00:00',
                ])],
            );
            $fake->script[] = new AiChatResponse(
                text: 'Scheduled from mail',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'stop',
            );

            $turn = app(ConversationTurnService::class)->handleUserMessage(
                $owner,
                $conversation,
                'Найди письмо от Марко с датой встречи и поставь её в календарь.',
                new ChannelContext(MessageChannel::Web, 'm19-loop-1'),
            );

            $this->assertSame('Scheduled from mail', $turn->assistantMessage?->body);
            $this->assertSame(1, $created);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($owner);
        }
    }

    /**
     * @return list<string>
     */
    private function gmailToolNames(): array
    {
        return [
            SearchGmailTool::NAME,
            SearchEmailTool::NAME,
            ListGmailMessagesTool::NAME,
            GetGmailMessageTool::NAME,
            GetGmailThreadTool::NAME,
            ListGmailLabelsTool::NAME,
            CreateGmailDraftTool::NAME,
            SendGmailMessageTool::NAME,
            ModifyGmailLabelsTool::NAME,
        ];
    }

    private function connectGoogle(User $owner, bool $calendar = true, bool $gmail = true): IntegrationAccount
    {
        $scopes = ['email', 'openid', 'profile'];
        if ($calendar) {
            $scopes[] = 'https://www.googleapis.com/auth/calendar';
        }
        if ($gmail) {
            $scopes = array_merge($scopes, [
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/gmail.compose',
                'https://www.googleapis.com/auth/gmail.modify',
            ]);
        }

        $accounts = app(IntegrationAccountService::class);
        $account = $accounts->upsertAccount(
            $owner,
            'google',
            'google-sub-1',
            'owner@example.test',
            IntegrationAccountStatus::Connected,
            $scopes,
        );
        $accounts->setCredentials($account, [
            'access_token' => 'synthetic-access-token',
            'refresh_token' => 'synthetic-refresh-token',
            'expires_at' => now()->addHour()->toIso8601String(),
            'token_type' => 'Bearer',
        ]);
        $accounts->markConnected($account);

        return $account->fresh() ?? $account;
    }

    private function configureGoogle(): void
    {
        config([
            'app.url' => 'https://jarvis.example.test',
            'integrations.google.client_id' => 'test-google-client-id',
            'integrations.google.client_secret' => 'test-google-client-secret',
            'integrations.google.redirect_uri' => 'https://jarvis.example.test/integrations/google/callback',
        ]);
    }

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }

    /**
     * @param  list<string>  $labels
     * @return array<string, mixed>
     */
    private function messageResource(
        string $id,
        string $subject,
        string $from,
        string $body,
        array $labels = ['INBOX'],
        string $threadId = 'th-1',
        int $internalDate = 1000,
        string $to = 'owner@example.test',
    ): array {
        return [
            'id' => $id,
            'threadId' => $threadId,
            'snippet' => mb_substr($body, 0, 40),
            'labelIds' => $labels,
            'internalDate' => (string) $internalDate,
            'payload' => [
                'mimeType' => 'text/plain',
                'headers' => $this->headers($subject, $from, $to),
                'body' => ['data' => $this->b64url($body)],
            ],
        ];
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function headers(string $subject, string $from, string $to = 'owner@example.test'): array
    {
        return [
            ['name' => 'Subject', 'value' => $subject],
            ['name' => 'From', 'value' => $from],
            ['name' => 'To', 'value' => $to],
            ['name' => 'Date', 'value' => 'Fri, 4 Sep 2026 10:00:00 +0200'],
        ];
    }

    private function gmailPath($request): string
    {
        return (string) (parse_url($request->url(), PHP_URL_PATH) ?? '');
    }

    private function isGmailMessageList($request): bool
    {
        return $request->method() === 'GET'
            && (bool) preg_match('#/gmail/v1/users/me/messages/?$#', $this->gmailPath($request));
    }

    private function gmailMessageId($request): ?string
    {
        if (preg_match('#/gmail/v1/users/me/messages/([A-Za-z0-9_-]+)$#', $this->gmailPath($request), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decodeRaw(string $raw): string
    {
        $padded = strtr($raw, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode($padded, true);
    }
}
