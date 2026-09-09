<?php

namespace Tests\Unit;

use App\Services\Ai\AiFailureFallback;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiEmptyResponseException;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Exceptions\AiSafetyException;
use App\Services\Tools\CompleteAssistantOnboardingTool;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\Storage\GetStorageFileTool;
use App\Services\Tools\Storage\ReadStorageFileChunksTool;
use App\Services\Tools\Storage\SearchStorageFileContentsTool;
use App\Services\Tools\Synthesis\ListCommitmentsTool;
use App\Services\Tools\Synthesis\ListWaitingForTool;
use App\Services\Tools\UpdateAssistantProfileTool;
use App\Services\Tools\Watchers\CreateWatcherTool;
use App\Services\Tools\Watchers\ListWatchersTool;
use PHPUnit\Framework\TestCase;

class AiFailureFallbackTest extends TestCase
{
    public function test_safety_block_gets_safe_user_response_without_tools(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiSafetyException('SAFETY'),
            [],
        );

        $this->assertSame(AiFailureFallback::SAFETY_RESPONSE, $fallback);
        $this->assertStringNotContainsString('ошибка ИИ', mb_strtolower((string) $fallback));
    }

    public function test_online_meeting_safety_block_gets_practical_guidance(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiSafetyException('SAFETY'),
            [],
            'Мне 11 лет, интернет-знакомый по фото выглядит взрослым и зовёт встретиться.',
        );

        $this->assertSame(AiFailureFallback::ONLINE_MEETING_RESPONSE, $fallback);
        $this->assertStringContainsString('доверенному взрослому', (string) $fallback);
        $this->assertStringNotContainsString('не могу ответить', mb_strtolower((string) $fallback));
    }

    public function test_successful_onboarding_tool_gets_completion_fallback(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiEmptyResponseException,
            [
                ToolResult::success('call-1', UpdateAssistantProfileTool::NAME, [
                    'success' => true,
                ]),
                ToolResult::success('call-2', CompleteAssistantOnboardingTool::NAME, [
                    'success' => true,
                ]),
            ],
        );

        $this->assertSame(
            'Готово, знакомство завершено. Я запомнил настройки и информацию о тебе.',
            $fallback,
        );
    }

    public function test_empty_response_asks_user_to_try_again(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiEmptyResponseException,
            [],
        );

        $this->assertSame(AiFailureFallback::ANSWER_UNAVAILABLE, $fallback);
    }

    public function test_technical_provider_failure_is_not_hidden(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiProviderException('upstream unavailable'),
            [],
        );

        $this->assertNull($fallback);
    }

    public function test_completed_mutation_keeps_safe_fallback_without_technical_error(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiProviderException('upstream unavailable'),
            [
                ToolResult::success('call-1', UpdateAssistantProfileTool::NAME, [
                    'success' => true,
                ]),
            ],
        );

        $this->assertSame(
            'Готово, настройки ассистента сохранены.',
            $fallback,
        );
        $this->assertStringNotContainsString('техническая ошибка', (string) $fallback);
    }

    public function test_successful_reminder_without_telegram_says_it_is_saved_in_jarvis(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiEmptyResponseException,
            [
                ToolResult::success('call-1', CreateReminderTool::NAME, [
                    'success' => true,
                    'text' => 'проверить чайник',
                    'telegram_connected' => false,
                ]),
            ],
        );

        $this->assertSame('Хорошо, напомню: проверить чайник. Оно сохранено в LAVR.', $fallback);
        $this->assertStringNotContainsString('подключите Telegram', mb_strtolower((string) $fallback));
    }

    public function test_successful_reminder_with_telegram_mentions_telegram_delivery(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiEmptyResponseException,
            [
                ToolResult::success('call-1', CreateReminderTool::NAME, [
                    'success' => true,
                    'text' => 'проверить чайник',
                    'telegram_connected' => true,
                ]),
            ],
        );

        $this->assertSame('Хорошо, напомню: проверить чайник. Я также пришлю его в Telegram.', $fallback);
    }

    public function test_successful_list_after_failed_watcher_does_not_claim_the_action_worked(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiProviderException('upstream unavailable'),
            [
                ToolResult::failure('call-1', CreateWatcherTool::NAME, [
                    'success' => false,
                    'error' => 'invalid_config',
                    'message' => 'Не получилось поставить автоматизацию: не хватает задачи или условия. Если речь о конкретной задаче, назовите её или уточните, о какой из недавних.',
                ]),
                ToolResult::success('call-2', ListWatchersTool::NAME, [
                    'success' => true,
                    'watchers' => [],
                ]),
            ],
        );

        $this->assertSame(
            'Не получилось поставить автоматизацию: не хватает задачи или условия. Если речь о конкретной задаче, назовите её или уточните, о какой из недавних.',
            $fallback,
        );
        $this->assertStringNotContainsString('Готово', (string) $fallback);
    }

    public function test_successful_list_alone_does_not_claim_the_action_worked(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiProviderException('upstream unavailable'),
            [
                ToolResult::success('call-1', ListWatchersTool::NAME, [
                    'success' => true,
                    'watchers' => [],
                ]),
            ],
        );

        $this->assertSame(AiFailureFallback::ANSWER_UNAVAILABLE, $fallback);
        $this->assertStringNotContainsString('Готово', (string) $fallback);
    }

    public function test_waiting_for_read_answers_even_if_follow_up_fails(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiProviderException('upstream unavailable'),
            [
                ToolResult::success('call-1', ListWaitingForTool::NAME, [
                    'success' => true,
                    'waiting_for' => [
                        [
                            'title' => 'Ждём выполнения «VC2 проверить новый билд»',
                            'why' => 'Если «VC2 проверить новый билд» завтра всё ещё будет открытой, я сообщу вам.',
                        ],
                    ],
                ]),
                ToolResult::success('call-2', ListCommitmentsTool::NAME, [
                    'success' => true,
                    'commitments' => [],
                ]),
            ],
            'Чего я сейчас жду?',
        );

        $this->assertSame(
            'Сейчас вы ждёте: Ждём выполнения «VC2 проверить новый билд». Если «VC2 проверить новый билд» завтра всё ещё будет открытой, я сообщу вам.',
            $fallback,
        );
        $this->assertStringNotContainsString('Готово', (string) $fallback);
        $this->assertStringNotContainsString('техническая ошибка', (string) $fallback);
    }

    public function test_empty_waiting_for_read_says_there_is_nothing_open(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiProviderException('upstream unavailable'),
            [
                ToolResult::success('call-1', ListWaitingForTool::NAME, [
                    'success' => true,
                    'waiting_for' => [],
                ]),
            ],
            'Чего я сейчас жду?',
        );

        $this->assertSame('Сейчас нет открытых ожиданий.', $fallback);
    }

    public function test_successful_watcher_reports_the_human_description_when_follow_up_fails(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiProviderException('upstream unavailable'),
            [
                ToolResult::success('call-1', CreateWatcherTool::NAME, [
                    'success' => true,
                    'description' => 'Если «VC2 проверить новый билд» завтра всё ещё будет открытой, я сообщу вам.',
                ]),
            ],
        );

        $this->assertSame(
            'Готово. Если «VC2 проверить новый билд» завтра всё ещё будет открытой, я сообщу вам.',
            $fallback,
        );
        $this->assertStringNotContainsString('техническая ошибка', (string) $fallback);
    }

    public function test_failed_create_watcher_does_not_claim_gmail_monitoring(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiProviderException('upstream unavailable'),
            [
                ToolResult::failure('call-1', CreateWatcherTool::NAME, [
                    'success' => false,
                    'error' => 'gmail_filter_required',
                    'message' => 'Не удалось создать мониторинг Gmail: нужен отправитель или домен.',
                    'kind' => 'failed',
                ]),
            ],
        );

        $this->assertSame('Не удалось создать мониторинг Gmail: нужен отправитель или домен.', $fallback);
        $this->assertStringNotContainsString('буду следить', mb_strtolower((string) $fallback));
        $this->assertStringNotContainsString('Готово', (string) $fallback);
    }

    public function test_storage_reads_do_not_claim_the_action_worked_when_follow_up_fails(): void
    {
        $fallback = (new AiFailureFallback)->resolve(
            new AiConfigurationException('AI tool loop exceeded the safety limit.'),
            [
                ToolResult::success('call-1', GetStorageFileTool::NAME, [
                    'success' => true,
                    'truncated' => true,
                ]),
                ToolResult::success('call-2', SearchStorageFileContentsTool::NAME, [
                    'success' => true,
                    'count' => 3,
                ]),
                ToolResult::success('call-3', ReadStorageFileChunksTool::NAME, [
                    'success' => true,
                    'count' => 1,
                    'chunks' => [['index' => 0, 'text' => 'G1 A90']],
                ]),
            ],
            'посмотри программу и посчитай сдвиг оси A',
        );

        $this->assertSame(AiFailureFallback::ANSWER_UNAVAILABLE, $fallback);
        $this->assertStringNotContainsString('Готово', (string) $fallback);
        $this->assertStringNotContainsString('техническая ошибка', (string) $fallback);
    }
}
