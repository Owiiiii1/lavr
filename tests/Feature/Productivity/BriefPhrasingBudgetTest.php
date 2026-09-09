<?php

namespace Tests\Feature\Productivity;

use App\Enums\AiRoleKey;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Ai\AiConfigurationResolver;
use App\Services\Ai\DTO\AiChatResponse;
use App\Services\Productivity\ProductivityBriefAiSynthesizer;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\RestoresAiRoleSettings;
use Tests\TestCase;

class BriefPhrasingBudgetTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresAiRoleSettings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshotAiRoleSettings();
        $this->enableRoleForTests(AiRoleKey::OwnerConversation);
    }

    protected function tearDown(): void
    {
        $this->restoreAiRoleSettings();

        parent::tearDown();
    }

    public function test_digest_phrasing_asks_for_a_budget_that_survives_reasoning_tokens(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $gateway = new FakeAiChatGateway;
            $gateway->responseText = 'Доброе утро! Из школы пришли материалы к первому дню и приглашение в Гардаленд.';

            $text = $this->synthesizer($gateway)->synthesize($user, 'mail_groups_digest', 'Почта и группы', []);

            $this->assertSame($gateway->responseText, $text);
            $this->assertSame(
                (int) config('productivity.briefs.phrasing_max_tokens'),
                $gateway->calls[0]['request']->maxTokens(),
            );
            $this->assertGreaterThanOrEqual(1200, $gateway->calls[0]['request']->maxTokens());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_phrasing_cut_off_by_the_token_limit_is_discarded(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $gateway = new FakeAiChatGateway;
            $gateway->script = [new AiChatResponse(
                text: 'Доброе утро! Давайте быстро пробежимся по свежей почте.',
                provider: 'gemini',
                model: 'fake-model',
                finishReason: 'MAX_TOKENS',
            )];

            $this->assertNull(
                $this->synthesizer($gateway)->synthesize($user, 'mail_groups_digest', 'Почта и группы', []),
            );
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_digest_prompt_demands_a_russian_retelling_instead_of_a_list(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $gateway = new FakeAiChatGateway;

            $this->synthesizer($gateway)->synthesize($user, 'mail_groups_digest', 'Почта и группы', []);

            $prompt = $gateway->calls[0]['request']->systemPrompt;
            $this->assertStringContainsString('Russian', $prompt);
            $this->assertStringContainsString('sender-subject list', $prompt);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    private function synthesizer(FakeAiChatGateway $gateway): ProductivityBriefAiSynthesizer
    {
        return new ProductivityBriefAiSynthesizer($gateway, app(AiConfigurationResolver::class));
    }

    private function owner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
