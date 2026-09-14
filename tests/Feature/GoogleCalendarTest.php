<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\IntegrationAccountStatus;
use App\Enums\MessageChannel;
use App\Enums\ToolConfirmationStatus;
use App\Enums\ToolExecutionLogStatus;
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
use App\Services\Integrations\Google\GoogleCalendarService;
use App\Services\Integrations\Google\GoogleCredentialService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Tools\CalendarEventIdempotency;
use App\Services\Tools\CancelToolActionTool;
use App\Services\Tools\ConfirmToolActionTool;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\Google\CreateCalendarEventTool;
use App\Services\Tools\Google\DeleteCalendarEventTool;
use App\Services\Tools\Google\GetCalendarEventTool;
use App\Services\Tools\Google\GoogleCalendarFreebusyTool;
use App\Services\Tools\Google\ListCalendarEventsTool;
use App\Services\Tools\Google\ListGoogleCalendarsTool;
use App\Services\Tools\Google\SearchCalendarEventsTool;
use App\Services\Tools\Google\UpdateCalendarEventTool;
use App\Services\Tools\SearchConversationHistoryTool;
use App\Services\Tools\ToolConfirmationService;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\Facades\Http;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\RestoresAiRoleSettings;
use Tests\Support\RestoresGoogleOAuthSettings;
use Tests\TestCase;

class GoogleCalendarTest extends TestCase
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

    public function test_owner_receives_calendar_tools_and_user_does_not(): void
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

            foreach ($this->calendarToolNames() as $name) {
                $this->assertContains($name, $ownerTools);
                $this->assertNotContains($name, $userTools);
            }

            $this->assertContains(CreateReminderTool::NAME, $userTools);
            $this->assertContains(SearchConversationHistoryTool::NAME, $userTools);
            $this->assertNotContains(ConfirmToolActionTool::NAME, $ownerTools);

            $forged = $registry->execute(
                new ToolCall('f1', ListGoogleCalendarsTool::NAME, []),
                new ToolExecutionContext($user, $userChat, explicitUserCommand: true),
            );
            $this->assertSame('tool_not_available', $forged->payload['error']);
            Http::assertNothingSent();
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_disconnected_owner_does_not_call_google(): void
    {
        $owner = null;

        try {
            Http::fake();
            $owner = $this->temporaryOwner();
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('d1', ListGoogleCalendarsTool::NAME, []),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertFalse($result->success);
            $this->assertSame('google_not_connected', $result->payload['error']);
            Http::assertNothingSent();
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_identity_only_account_requires_calendar_scope(): void
    {
        $owner = null;

        try {
            Http::fake();
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner, calendar: false);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('s1', ListCalendarEventsTool::NAME, [
                    'time_min' => '2026-09-05T00:00:00',
                    'time_max' => '2026-09-06T00:00:00',
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertSame('calendar_scope_required', $result->payload['error']);
            Http::assertNothingSent();
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_incremental_oauth_merges_scopes_and_keeps_refresh_token(): void
    {
        $owner = null;

        try {
            $this->configureGoogle();
            $owner = $this->temporaryOwner();
            $account = $this->connectGoogle($owner, calendar: false);

            $response = $this->actingAs($owner)->get(route('integrations.google.connect', ['intent' => 'calendar']));
            $response->assertRedirect();
            $location = $response->headers->get('Location');
            parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
            $this->assertStringContainsString('openid', (string) ($query['scope'] ?? ''));
            $this->assertStringContainsString('https://www.googleapis.com/auth/calendar', (string) ($query['scope'] ?? ''));
            $this->assertSame('true', $query['include_granted_scopes'] ?? null);

            $before = $this->actingAs($owner)->get(route('settings.index', ['tab' => 'integrations']));
            $this->assertStringContainsString('Enable Calendar', $before->getContent());
            $this->assertStringContainsString('permission_required', $before->getContent());
            $this->assertStringContainsString('"key":"gmail"', $before->getContent());
            $this->assertStringContainsString('permission_required', $before->getContent());

            Http::fake([
                'https://oauth2.googleapis.com/token' => Http::response([
                    'access_token' => 'synthetic-access-token-calendar',
                    'expires_in' => 3600,
                    'token_type' => 'Bearer',
                    'scope' => 'https://www.googleapis.com/auth/calendar',
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
                'code' => 'synthetic-calendar-code',
            ]))->assertRedirect(route('settings.index', ['tab' => 'integrations']));

            $account->refresh();
            $this->assertContains('openid', $account->scopes ?? []);
            $this->assertContains('https://www.googleapis.com/auth/calendar', $account->scopes ?? []);
            $envelope = app(IntegrationAccountService::class)->getCredentials($account);
            $this->assertSame('synthetic-refresh-token', $envelope['refresh_token']);
            $this->assertSame('synthetic-access-token-calendar', $envelope['access_token']);

            $page = $this->actingAs($owner)->get(route('settings.index', ['tab' => 'integrations']));
            $this->assertStringContainsString('"key":"calendar"', $page->getContent());
            $this->assertStringContainsString('"state":"enabled"', $page->getContent());
            $this->assertStringContainsString('"key":"gmail"', $page->getContent());
            $this->assertStringContainsString('permission_required', $page->getContent());
            $this->assertStringNotContainsString('Enable Calendar', $page->getContent());
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_list_calendars_is_normalized_and_bounded(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            Http::fake([
                'https://www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
                    'items' => [
                        ['id' => 'primary', 'summary' => 'Owner', 'primary' => true, 'accessRole' => 'owner', 'timeZone' => 'Europe/Rome', 'selected' => true],
                        ['id' => 'work@example.test', 'summary' => 'Work', 'accessRole' => 'writer', 'timeZone' => 'UTC'],
                    ],
                    'nextPageToken' => 'more',
                ], 200),
            ]);

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('c1', ListGoogleCalendarsTool::NAME, ['max_results' => 1]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertTrue($result->success);
            $this->assertCount(1, $result->payload['calendars']);
            $this->assertTrue($result->payload['truncated']);
            $this->assertSame('primary', $result->payload['calendars'][0]['id']);
            $this->assertTrue($result->payload['calendars'][0]['primary']);
            $this->assertArrayNotHasKey('etag', $result->payload['calendars'][0]);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_list_events_normalizes_timed_and_all_day(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            Http::fake([
                'https://www.googleapis.com/calendar/v3/calendars/*' => Http::response([
                    'items' => [
                        [
                            'id' => 'timed-1',
                            'summary' => 'Standup',
                            'start' => ['dateTime' => '2026-09-05T10:00:00+02:00', 'timeZone' => 'Europe/Rome'],
                            'end' => ['dateTime' => '2026-09-05T10:30:00+02:00', 'timeZone' => 'Europe/Rome'],
                            'etag' => '"etag-1"',
                        ],
                        [
                            'id' => 'allday-1',
                            'summary' => 'Holiday',
                            'start' => ['date' => '2026-09-06'],
                            'end' => ['date' => '2026-09-07'],
                        ],
                    ],
                    'nextPageToken' => 'page-2',
                ], 200),
            ]);

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('e1', ListCalendarEventsTool::NAME, [
                    'time_min' => '2026-09-05T00:00:00',
                    'time_max' => '2026-09-07T00:00:00',
                    'max_results' => 2,
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertTrue($result->success);
            $this->assertFalse($result->payload['events'][0]['all_day']);
            $this->assertTrue($result->payload['events'][1]['all_day']);
            $this->assertSame('2026-09-06', $result->payload['events'][1]['start']);
            $this->assertSame('etag-1', $result->payload['events'][0]['etag']);
            $this->assertTrue($result->payload['truncated']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_search_applies_query_and_range(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            Http::fake([
                'https://www.googleapis.com/calendar/v3/calendars/*' => function ($request) {
                    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                    $this->assertSame('Иван', $query['q'] ?? null);
                    $this->assertNotEmpty($query['timeMin'] ?? null);
                    $this->assertNotEmpty($query['timeMax'] ?? null);
                    $this->assertSame('startTime', $query['orderBy'] ?? null);

                    return Http::response(['items' => []], 200);
                },
            ]);

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('q1', SearchCalendarEventsTool::NAME, ['query' => 'Иван']),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertTrue($result->success);
            $this->assertSame(0, $result->payload['result_count']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_freebusy_is_normalized_and_capped(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            Http::fake([
                'https://www.googleapis.com/calendar/v3/freeBusy' => function ($request) {
                    $body = $request->data();
                    $this->assertSame('Europe/Rome', $body['timeZone'] ?? null);
                    $this->assertLessThanOrEqual(20, count($body['items'] ?? []));

                    return Http::response([
                        'calendars' => [
                            'primary' => [
                                'busy' => [
                                    ['start' => '2026-09-05T15:00:00+02:00', 'end' => '2026-09-05T16:00:00+02:00'],
                                ],
                            ],
                        ],
                    ], 200);
                },
            ]);

            $tooWide = app(ToolRegistry::class)->execute(
                new ToolCall('fb0', GoogleCalendarFreebusyTool::NAME, [
                    'time_min' => '2026-01-01T00:00:00',
                    'time_max' => '2026-12-31T00:00:00',
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $this->assertSame('invalid_arguments', $tooWide->payload['error']);

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('fb1', GoogleCalendarFreebusyTool::NAME, [
                    'time_min' => '2026-09-05T15:00:00',
                    'time_max' => '2026-09-05T17:00:00',
                    'calendar_ids' => ['primary'],
                    'timezone' => 'Europe/Rome',
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertTrue($result->success);
            $this->assertTrue($result->payload['has_busy']);
            $this->assertSame('2026-09-05T15:00:00+02:00', $result->payload['calendars']['primary']['busy'][0]['start']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_create_event_explicit_command_updates_account_health(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $account = $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            Http::fake([
                'https://www.googleapis.com/calendar/v3/calendars/*' => Http::response([
                    'id' => 'created-1',
                    'summary' => 'Пётр',
                    'htmlLink' => 'https://calendar.google.com/event?eid=test',
                    'start' => ['dateTime' => '2026-09-05T14:00:00', 'timeZone' => 'Europe/Rome'],
                    'end' => ['dateTime' => '2026-09-05T15:00:00', 'timeZone' => 'Europe/Rome'],
                    'attendees' => [['email' => 'p@example.test', 'responseStatus' => 'needsAction']],
                ], 200),
            ]);

            $result = app(ToolRegistry::class)->execute(
                new ToolCall('cr1', CreateCalendarEventTool::NAME, [
                    'title' => 'Пётр',
                    'start' => '2026-09-05T14:00:00',
                    'duration_minutes' => 60,
                    'attendees' => ['p@example.test'],
                    'send_updates' => 'none',
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertTrue($result->success);
            $this->assertSame('Пётр', $result->payload['event']['title']);
            $this->assertSame('https://calendar.google.com/event?eid=test', $result->payload['event']['html_link']);
            $account->refresh();
            $this->assertNotNull($account->last_used_at);
            $this->assertNotNull($account->last_success_at);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_create_is_idempotent_for_the_same_tool_call(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $expectedId = CalendarEventIdempotency::googleEventId((int) $owner->id, (int) $chat->id, 'same-call');
            $seen = [];

            Http::fake([
                'https://www.googleapis.com/calendar/v3/calendars/*' => function ($request) use (&$seen, $expectedId) {
                    if ($request->method() === 'POST') {
                        $seen[] = $request->data()['id'] ?? null;
                        $this->assertSame($expectedId, $request->data()['id'] ?? null);

                        return Http::response([
                            'id' => $expectedId,
                            'summary' => 'Stable',
                            'start' => ['dateTime' => '2026-09-05T14:00:00', 'timeZone' => 'Europe/Rome'],
                            'end' => ['dateTime' => '2026-09-05T15:00:00', 'timeZone' => 'Europe/Rome'],
                        ], 200);
                    }

                    return Http::response(['id' => $expectedId, 'summary' => 'Stable'], 200);
                },
            ]);

            $call = new ToolCall('same-call', CreateCalendarEventTool::NAME, [
                'title' => 'Stable',
                'start' => '2026-09-05T14:00:00',
                'end' => '2026-09-05T15:00:00',
            ]);
            $context = new ToolExecutionContext($owner, $chat, explicitUserCommand: true);

            $first = app(ToolRegistry::class)->execute($call, $context);
            $second = app(ToolRegistry::class)->execute($call, $context);

            $this->assertTrue($first->success);
            $this->assertTrue($second->success);
            $this->assertSame([$expectedId, $expectedId], $seen);
            $this->assertMatchesRegularExpression('/^jvs[0-9a-f]{40}$/', $expectedId);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_model_proposed_write_requires_confirmation_without_google_http(): void
    {
        $owner = null;

        try {
            Http::fake();
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');

            $create = app(ToolRegistry::class)->execute(
                new ToolCall('pw1', CreateCalendarEventTool::NAME, [
                    'title' => 'Proposed',
                    'start' => '2026-09-05T14:00:00',
                    'end' => '2026-09-05T15:00:00',
                    'authorized' => true,
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: false),
            );
            $update = app(ToolRegistry::class)->execute(
                new ToolCall('pw2', UpdateCalendarEventTool::NAME, [
                    'event_id' => 'evt-1',
                    'title' => 'Moved',
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: false),
            );

            $this->assertSame('confirmation_required', $create->payload['error']);
            $this->assertSame('confirmation_required', $update->payload['error']);
            $this->assertNotEmpty($create->payload['confirmation_id'] ?? null);
            Http::assertNothingSent();
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_update_patches_supplied_fields_and_normalizes_conflict(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            Http::fake([
                'https://www.googleapis.com/calendar/v3/calendars/*' => function ($request) {
                    if ($request->method() === 'PATCH' && str_contains($request->url(), 'evt-ok')) {
                        $this->assertSame(['summary' => 'New title'], $request->data());
                        $this->assertSame('"etag-1"', $request->header('If-Match')[0] ?? null);

                        return Http::response([
                            'id' => 'evt-ok',
                            'summary' => 'New title',
                            'etag' => '"etag-2"',
                            'start' => ['dateTime' => '2026-09-05T14:00:00+02:00'],
                            'end' => ['dateTime' => '2026-09-05T15:00:00+02:00'],
                        ], 200);
                    }

                    return Http::response(['error' => ['status' => 'ABORTED']], 412);
                },
            ]);

            $ok = app(ToolRegistry::class)->execute(
                new ToolCall('u1', UpdateCalendarEventTool::NAME, [
                    'event_id' => 'evt-ok',
                    'title' => 'New title',
                    'etag' => 'etag-1',
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $this->assertTrue($ok->success);
            $this->assertSame('New title', $ok->payload['event']['title']);

            $conflict = app(ToolRegistry::class)->execute(
                new ToolCall('u2', UpdateCalendarEventTool::NAME, [
                    'event_id' => 'evt-conflict',
                    'title' => 'Other',
                    'etag' => 'stale',
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $this->assertSame('calendar_conflict', $conflict->payload['error']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_delete_confirmation_is_one_time_and_cross_user_denied(): void
    {
        $owner = null;
        $other = null;

        try {
            $owner = $this->temporaryOwner();
            $other = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $otherChat = app(ConversationService::class)->createPersonal($other, 'Основной');
            $deletes = 0;
            Http::fake([
                'https://www.googleapis.com/calendar/v3/calendars/*' => function ($request) use (&$deletes) {
                    if ($request->method() === 'DELETE') {
                        $deletes++;

                        return Http::response('', 204);
                    }

                    return Http::response(['error' => ['status' => 'NOT_FOUND']], 404);
                },
            ]);

            $initial = app(ToolRegistry::class)->execute(
                new ToolCall('del1', DeleteCalendarEventTool::NAME, ['event_id' => 'evt-del']),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $this->assertSame('confirmation_required', $initial->payload['error']);
            $this->assertSame(0, $deletes);

            $confirmation = ToolConfirmation::query()->where('user_id', $owner->id)->first();
            $this->assertNotNull($confirmation);
            $this->assertSame(ToolConfirmationStatus::Pending, $confirmation->status);

            $otherConfirm = app(ToolRegistry::class)->execute(
                new ToolCall('xc1', ConfirmToolActionTool::NAME, ['confirmation_id' => $confirmation->public_id]),
                new ToolExecutionContext($other, $otherChat, explicitUserCommand: true, confirmationIntent: 'confirm'),
            );
            $this->assertSame('tool_not_available', $otherConfirm->payload['error']);
            $this->assertSame(0, $deletes);

            $selfConfirmDenied = app(ToolRegistry::class)->execute(
                new ToolCall('mc1', ConfirmToolActionTool::NAME, ['confirmation_id' => $confirmation->public_id]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $this->assertSame('confirmation_not_affirmed', $selfConfirmDenied->payload['error']);
            $this->assertSame(0, $deletes);

            $executed = app(ToolConfirmationService::class)->executeConfirmed(
                $confirmation,
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true, confirmationIntent: 'confirm'),
                app(ToolRegistry::class),
            );
            $this->assertTrue($executed->success);
            $this->assertSame(1, $deletes);

            $repeat = app(ToolConfirmationService::class)->executeConfirmed(
                $confirmation->fresh(),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true, confirmationIntent: 'confirm'),
                app(ToolRegistry::class),
            );
            $this->assertSame('confirmation_already_executed', $repeat->payload['error']);
            $this->assertSame(1, $deletes);
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($other);
        }
    }

    public function test_expired_and_cancelled_confirmations_do_not_execute(): void
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
                DeleteCalendarEventTool::NAME,
                ['event_id' => 'evt-exp'],
                'exp-1',
            );
            $expired->forceFill(['expires_at' => now()->subMinute()])->save();

            $expiredResult = app(ToolConfirmationService::class)->executeConfirmed(
                $expired,
                new ToolExecutionContext($owner, $chat, confirmationIntent: 'confirm'),
                app(ToolRegistry::class),
            );
            $this->assertSame('confirmation_expired', $expiredResult->payload['error']);

            $cancelPending = app(ToolRegistry::class)->execute(
                new ToolCall('del2', DeleteCalendarEventTool::NAME, ['event_id' => 'evt-can']),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $this->assertSame('confirmation_required', $cancelPending->payload['error']);
            $pending = ToolConfirmation::query()->where('public_id', $cancelPending->payload['confirmation_id'])->first();

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

    public function test_calendar_service_refreshes_via_credential_service(): void
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
                'https://www.googleapis.com/calendar/v3/users/me/calendarList*' => function ($request) {
                    $this->assertSame('Bearer refreshed-access', $request->header('Authorization')[0] ?? null);

                    return Http::response(['items' => [['id' => 'primary', 'summary' => 'Owner', 'primary' => true]]], 200);
                },
            ]);

            $result = app(GoogleCalendarService::class)->listCalendars($account->fresh());
            $this->assertSame('primary', $result['calendars'][0]['id']);
            Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/token'));
            $this->assertSame('refreshed-access', app(GoogleCredentialService::class)->getValidAccessToken($account->fresh()));
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_revoked_refresh_does_not_call_calendar(): void
    {
        $owner = null;

        try {
            $this->configureGoogle();
            $owner = $this->temporaryOwner();
            $account = $this->connectGoogle($owner);
            app(IntegrationAccountService::class)->setCredentials($account, [
                'access_token' => 'expired-access',
                'refresh_token' => 'revoked-refresh',
                'expires_at' => now()->subMinute()->toIso8601String(),
            ]);

            $calendarHits = 0;
            Http::fake([
                'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
                'https://www.googleapis.com/calendar/v3/*' => function () use (&$calendarHits) {
                    $calendarHits++;

                    return Http::response(['items' => []], 200);
                },
            ]);

            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $result = app(ToolRegistry::class)->execute(
                new ToolCall('rv1', ListGoogleCalendarsTool::NAME, []),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertSame('refresh_revoked', $result->payload['error']);
            $this->assertSame(0, $calendarHits);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_create_uses_owner_timezone_around_dst(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $owner->forceFill(['timezone' => 'Europe/Rome'])->save();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $payloads = [];
            Http::fake([
                'https://www.googleapis.com/calendar/v3/calendars/*' => function ($request) use (&$payloads) {
                    $payloads[] = $request->data();

                    return Http::response([
                        'id' => $request->data()['id'] ?? 'dst',
                        'summary' => 'DST',
                        'start' => $request->data()['start'] ?? [],
                        'end' => $request->data()['end'] ?? [],
                    ], 200);
                },
            ]);

            app(ToolRegistry::class)->execute(
                new ToolCall('dst1', CreateCalendarEventTool::NAME, [
                    'title' => 'Before jump',
                    'start' => '2026-03-29T01:30:00',
                    'end' => '2026-03-29T03:30:00',
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $this->assertSame('2026-03-29T01:30:00', $payloads[0]['start']['dateTime']);
            $this->assertSame('Europe/Rome', $payloads[0]['start']['timeZone']);
            $this->assertSame('2026-03-29T03:30:00', $payloads[0]['end']['dateTime']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_logs_do_not_contain_secrets_or_event_content(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $this->connectGoogle($owner);
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            Http::fake([
                'https://www.googleapis.com/calendar/v3/calendars/*' => Http::response([
                    'id' => 'secret-event',
                    'summary' => 'Private title',
                    'description' => 'Secret agenda',
                    'attendees' => [['email' => 'hidden@example.test']],
                    'start' => ['dateTime' => '2026-09-05T14:00:00+02:00'],
                    'end' => ['dateTime' => '2026-09-05T15:00:00+02:00'],
                ], 200),
            ]);

            app(ToolRegistry::class)->execute(
                new ToolCall('sec1', CreateCalendarEventTool::NAME, [
                    'title' => 'Private title',
                    'description' => 'Secret agenda',
                    'start' => '2026-09-05T14:00:00',
                    'end' => '2026-09-05T15:00:00',
                    'attendees' => ['hidden@example.test'],
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );

            $log = ToolExecutionLog::query()->where('user_id', $owner->id)->latest('id')->first();
            $encoded = json_encode($log?->toArray());
            $this->assertStringNotContainsString('synthetic-access-token', (string) $encoded);
            $this->assertStringNotContainsString('synthetic-refresh-token', (string) $encoded);
            $this->assertStringNotContainsString('Secret agenda', (string) $encoded);
            $this->assertStringNotContainsString('hidden@example.test', (string) $encoded);
            $this->assertSame('google', $log?->provider);
            $this->assertSame(ToolExecutionLogStatus::Succeeded, $log?->status);
            $this->assertSame(1, $log?->metadata['result_count'] ?? null);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_multi_tool_loops_run_sequentially(): void
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
            Http::fake(function ($request) {
                $url = $request->url();

                if (str_contains($url, '/freeBusy')) {
                    return Http::response(['calendars' => ['primary' => ['busy' => []]]], 200);
                }

                if ($request->method() === 'POST' && str_contains($url, '/events')) {
                    return Http::response([
                        'id' => $request->data()['id'] ?? 'created-loop',
                        'summary' => 'New',
                        'start' => ['dateTime' => '2026-09-05T14:00:00', 'timeZone' => 'Europe/Rome'],
                        'end' => ['dateTime' => '2026-09-05T15:00:00', 'timeZone' => 'Europe/Rome'],
                    ], 200);
                }

                if ($request->method() === 'PATCH') {
                    return Http::response([
                        'id' => 'meet-ivan',
                        'summary' => 'Иван',
                        'start' => ['dateTime' => '2026-09-11T10:00:00+02:00'],
                        'end' => ['dateTime' => '2026-09-11T11:00:00+02:00'],
                    ], 200);
                }

                if (str_contains($url, 'meet-ivan')) {
                    return Http::response([
                        'id' => 'meet-ivan',
                        'summary' => 'Иван',
                        'start' => ['dateTime' => '2026-09-08T10:00:00+02:00'],
                        'end' => ['dateTime' => '2026-09-08T11:00:00+02:00'],
                        'etag' => '"e1"',
                    ], 200);
                }

                return Http::response([
                    'items' => [[
                        'id' => 'meet-ivan',
                        'summary' => 'Иван',
                        'start' => ['dateTime' => '2026-09-08T10:00:00+02:00'],
                        'end' => ['dateTime' => '2026-09-08T11:00:00+02:00'],
                        'etag' => '"e1"',
                    ]],
                ], 200);
            });

            $fake->script[] = new AiChatResponse(
                text: '',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'tool_calls',
                toolCalls: [new ToolCall('l1', GoogleCalendarFreebusyTool::NAME, [
                    'time_min' => '2026-09-05T15:00:00',
                    'time_max' => '2026-09-05T17:00:00',
                ])],
            );
            $fake->script[] = new AiChatResponse(
                text: '',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'tool_calls',
                toolCalls: [new ToolCall('l2', CreateCalendarEventTool::NAME, [
                    'title' => 'New',
                    'start' => '2026-09-05T14:00:00',
                    'end' => '2026-09-05T15:00:00',
                ])],
            );
            $fake->script[] = new AiChatResponse(
                text: 'Scheduled',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'stop',
            );

            $first = app(ConversationTurnService::class)->handleUserMessage(
                $owner,
                $conversation,
                'Свободен ли я и создай встречу',
                new ChannelContext(MessageChannel::Web, 'm18-loop-1'),
            );
            $this->assertSame('Scheduled', $first->assistantMessage?->body);

            $fake->script[] = new AiChatResponse(
                text: '',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'tool_calls',
                toolCalls: [new ToolCall('l3', SearchCalendarEventsTool::NAME, ['query' => 'Иван'])],
            );
            $fake->script[] = new AiChatResponse(
                text: '',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'tool_calls',
                toolCalls: [new ToolCall('l4', GetCalendarEventTool::NAME, ['event_id' => 'meet-ivan'])],
            );
            $fake->script[] = new AiChatResponse(
                text: '',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'tool_calls',
                toolCalls: [new ToolCall('l5', UpdateCalendarEventTool::NAME, [
                    'event_id' => 'meet-ivan',
                    'start' => '2026-09-11T10:00:00',
                    'end' => '2026-09-11T11:00:00',
                    'etag' => 'e1',
                ])],
            );
            $fake->script[] = new AiChatResponse(
                text: 'Moved',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'stop',
            );

            $second = app(ConversationTurnService::class)->handleUserMessage(
                $owner,
                $conversation,
                'Перенеси встречу с Иваном на пятницу',
                new ChannelContext(MessageChannel::Web, 'm18-loop-2'),
            );
            $this->assertSame('Moved', $second->assistantMessage?->body);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_get_event_and_affirmative_delete_flow(): void
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
            $deletes = 0;
            Http::fake(function ($request) use (&$deletes) {
                if ($request->method() === 'DELETE') {
                    $deletes++;

                    return Http::response('', 204);
                }

                return Http::response([
                    'id' => 'evt-get',
                    'summary' => 'Review',
                    'start' => ['dateTime' => '2026-09-05T14:00:00+02:00'],
                    'end' => ['dateTime' => '2026-09-05T15:00:00+02:00'],
                ], 200);
            });

            $get = app(ToolRegistry::class)->execute(
                new ToolCall('g1', GetCalendarEventTool::NAME, ['event_id' => 'evt-get']),
                new ToolExecutionContext($owner, $conversation, explicitUserCommand: true),
            );
            $this->assertTrue($get->success);
            $this->assertSame('Review', $get->payload['event']['title']);

            $fake->script[] = new AiChatResponse(
                text: '',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'tool_calls',
                toolCalls: [new ToolCall('d1', DeleteCalendarEventTool::NAME, ['event_id' => 'evt-get'])],
            );
            $fake->script[] = new AiChatResponse(
                text: 'Подтвердить удаление встречи Review?',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'stop',
            );

            $ask = app(ConversationTurnService::class)->handleUserMessage(
                $owner,
                $conversation,
                'Удали встречу Review',
                new ChannelContext(MessageChannel::Web, 'm18-del-1'),
            );
            $this->assertSame(0, $deletes);
            $this->assertNotNull($ask->assistantMessage?->metadata['pending_confirmation']['id'] ?? null);

            $fake->script[] = new AiChatResponse(
                text: 'Встреча удалена.',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'stop',
            );

            $yes = app(ConversationTurnService::class)->handleUserMessage(
                $owner,
                $conversation,
                'да',
                new ChannelContext(MessageChannel::Web, 'm18-del-2'),
            );
            $this->assertSame(1, $deletes);
            $this->assertSame('Встреча удалена.', $yes->assistantMessage?->body);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($owner);
        }
    }

    /**
     * @return list<string>
     */
    private function calendarToolNames(): array
    {
        return [
            ListGoogleCalendarsTool::NAME,
            ListCalendarEventsTool::NAME,
            GetCalendarEventTool::NAME,
            SearchCalendarEventsTool::NAME,
            GoogleCalendarFreebusyTool::NAME,
            CreateCalendarEventTool::NAME,
            UpdateCalendarEventTool::NAME,
            DeleteCalendarEventTool::NAME,
        ];
    }

    private function connectGoogle(User $owner, bool $calendar = true): IntegrationAccount
    {
        $scopes = ['email', 'openid', 'profile'];
        if ($calendar) {
            $scopes[] = 'https://www.googleapis.com/auth/calendar';
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
}
