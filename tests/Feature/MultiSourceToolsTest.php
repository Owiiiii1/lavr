<?php

namespace Tests\Feature;

use App\Enums\IntegrationAccountStatus;
use App\Enums\ProjectSourceType;
use App\Enums\UserRole;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Conversations\ConversationService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Projects\ProjectService;
use App\Services\Sources\ProjectSourceBindingService;
use App\Services\Tools\Sources\BindProjectSourceTool;
use App\Services\Tools\Sources\ListIntegrationsTool;
use App\Services\Tools\Sources\ListProjectSourcesTool;
use App\Services\Tools\Sources\RenameSourceLabelTool;
use App\Services\Tools\Sources\SearchEmailTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\Facades\Http;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\InterpretsHttpClientRequests;
use Tests\TestCase;

class MultiSourceToolsTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use InterpretsHttpClientRequests;

    public function test_list_integrations_and_project_sources_include_freshness(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $account = $this->connectGoogle($owner, 'google-sub-chi', 'chicago@example.test', 'Chicago');
            $project = app(ProjectService::class)->create($owner, 'Chicago');
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $registry = app(ToolRegistry::class);

            $bind = $registry->execute(
                new ToolCall('b1', BindProjectSourceTool::NAME, [
                    'project_id' => $project->id,
                    'source_type' => ProjectSourceType::GoogleMailbox->value,
                    'source_id' => $account->id,
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $this->assertTrue($bind->success);

            $rename = $registry->execute(
                new ToolCall('r1', RenameSourceLabelTool::NAME, [
                    'account_id' => $account->id,
                    'label' => 'YFS Chicago',
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $this->assertTrue($rename->success);
            $this->assertSame('YFS Chicago', $account->fresh()->display_label);

            $list = $registry->execute(
                new ToolCall('i1', ListIntegrationsTool::NAME, []),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $this->assertTrue($list->success);
            $this->assertNotEmpty($list->payload['google']);
            $this->assertSame('YFS Chicago', $list->payload['google'][0]['label']);
            $this->assertArrayHasKey('health', $list->payload['google'][0]);
            $this->assertArrayNotHasKey('credentials_encrypted', $list->payload['google'][0]);

            $sources = $registry->execute(
                new ToolCall('p1', ListProjectSourcesTool::NAME, ['project_id' => $project->id]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $this->assertTrue($sources->success);
            $this->assertCount(1, $sources->payload['sources']);
            $this->assertSame($account->id, $sources->payload['sources'][0]['source_id']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_search_email_scopes_account_project_and_reports_unavailable_mailboxes(): void
    {
        $owner = null;

        try {
            $owner = $this->temporaryOwner();
            $chicago = $this->connectGoogle($owner, 'google-sub-chi', 'chicago@example.test', 'Chicago');
            $finance = $this->connectGoogle($owner, 'google-sub-fin', 'finance@example.test', 'Finance');
            $project = app(ProjectService::class)->create($owner, 'Chicago');
            app(ProjectSourceBindingService::class)->bind(
                $owner,
                $project,
                ProjectSourceType::GoogleMailbox,
                $chicago->id,
            );
            $chat = app(ConversationService::class)->createPersonal($owner, 'Основной');

            Http::fake(function ($request) {
                $auth = $this->httpAuthorization($request);
                if (str_contains($auth, 'token-google-sub-fin')) {
                    return Http::response(['error' => ['status' => 'UNAUTHENTICATED']], 401);
                }

                if ($request->method() === 'GET' && preg_match('#/gmail/v1/users/me/messages/?$#', (string) parse_url($request->url(), PHP_URL_PATH)) === 1) {
                    return Http::response(['messages' => [['id' => 'm-chi']]], 200);
                }

                return Http::response([
                    'id' => 'm-chi',
                    'threadId' => 'th-chi',
                    'snippet' => 'Sony contract',
                    'labelIds' => ['INBOX'],
                    'payload' => [
                        'headers' => [
                            ['name' => 'From', 'value' => 'Sony <sony@example.test>'],
                            ['name' => 'Subject', 'value' => 'Contract'],
                            ['name' => 'Date', 'value' => 'Mon, 14 Sep 2026 10:00:00 +0000'],
                        ],
                    ],
                ], 200);
            });

            $registry = app(ToolRegistry::class);
            $all = $registry->execute(
                new ToolCall('s1', SearchEmailTool::NAME, ['query' => 'from:Sony']),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $this->assertTrue($all->success);
            $this->assertSame('partial', $all->payload['semantics']);
            $this->assertStringContainsString('Finance', $all->payload['freshness']);
            $this->assertCount(1, $all->payload['messages']);
            $this->assertSame($chicago->id, $all->payload['messages'][0]['account_id']);

            $scoped = $registry->execute(
                new ToolCall('s2', SearchEmailTool::NAME, [
                    'query' => 'from:Sony',
                    'account_id' => $chicago->id,
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $this->assertTrue($scoped->success);
            $this->assertSame('ok', $scoped->payload['semantics']);
            $this->assertSame([], $scoped->payload['unavailable']);

            $projectScoped = $registry->execute(
                new ToolCall('s3', SearchEmailTool::NAME, [
                    'query' => 'from:Sony',
                    'project_id' => $project->id,
                ]),
                new ToolExecutionContext($owner, $chat, explicitUserCommand: true),
            );
            $this->assertTrue($projectScoped->success);
            $this->assertSame($chicago->id, $projectScoped->payload['messages'][0]['account_id']);
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }

    private function connectGoogle(User $owner, string $sub, string $email, string $label): IntegrationAccount
    {
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

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
