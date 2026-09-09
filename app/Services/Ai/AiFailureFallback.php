<?php

namespace App\Services\Ai;

use App\Services\Ai\DTO\ToolResult;
use App\Services\Ai\Exceptions\AiEmptyResponseException;
use App\Services\Ai\Exceptions\AiSafetyException;
use App\Services\Tools\CompleteAssistantOnboardingTool;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\CreateTaskTool;
use App\Services\Tools\SetTelegramResponseModeTool;
use App\Services\Tools\Synthesis\GetSynthesisTool;
use App\Services\Tools\Synthesis\ListCommitmentsTool;
use App\Services\Tools\Synthesis\ListWaitingForTool;
use App\Services\Tools\ToolSemantics;
use App\Services\Tools\UpdateAssistantProfileTool;
use App\Services\Tools\Watchers\CreateWatcherTool;
use Throwable;

final class AiFailureFallback
{
    public const ANSWER_UNAVAILABLE = 'Сейчас не удалось сформировать ответ. Попробуй ещё раз.';

    public const SAFETY_RESPONSE = 'В этой ситуации важно выбрать безопасный вариант. Не предпринимай опасных действий и не оставайся с риском один на один: обратись к доверенному человеку или специалисту, а при непосредственной угрозе — в экстренную службу. Я могу помочь продумать безопасные следующие шаги.';

    public const ONLINE_MEETING_RESPONSE = 'Не соглашайся встречаться с интернет-знакомым наедине, особенно если его возраст вызывает сомнения. Не сообщай адрес, школу, телефон и другие личные данные. Покажи переписку родителю или другому доверенному взрослому и принимай решение только вместе с ним. Если человек давит, просит сохранить встречу в секрете или прислать личные фотографии — прекрати общение и заблокируй его.';

    /**
     * @var list<string>
     */
    private const KNOWN_MUTATIONS = [
        CompleteAssistantOnboardingTool::NAME,
        CreateReminderTool::NAME,
        CreateTaskTool::NAME,
        CreateWatcherTool::NAME,
        SetTelegramResponseModeTool::NAME,
        UpdateAssistantProfileTool::NAME,
    ];

    public function __construct(
        private readonly ?ToolSemantics $semantics = null,
    ) {}

    /**
     * @param  list<ToolResult>  $results
     */
    public function resolve(Throwable $exception, array $results, ?string $userText = null): ?string
    {
        foreach (array_reverse($results) as $result) {
            if ($result->name !== CreateReminderTool::NAME) {
                continue;
            }

            if ($result->success) {
                $text = trim((string) ($result->payload['text'] ?? ''));
                $linked = (bool) ($result->payload['telegram_connected'] ?? false);
                $reply = $text === ''
                    ? 'Хорошо, напоминание создано.'
                    : 'Хорошо, напомню: '.$text.'.';

                if ($linked) {
                    $reply .= ' Я также пришлю его в Telegram.';
                } else {
                    $reply .= ' Оно сохранено в LAVR.';
                }

                return $reply;
            }
        }

        foreach (array_reverse($results) as $result) {
            if (! $this->isMutationResult($result)) {
                continue;
            }

            if (! $result->success) {
                $message = trim((string) ($result->payload['message'] ?? ''));

                return $message !== '' ? $message : 'Не получилось выполнить действие. Попробуйте ещё раз.';
            }

            return match ($result->name) {
                CompleteAssistantOnboardingTool::NAME => 'Готово, знакомство завершено. Я запомнил настройки и информацию о тебе.',
                UpdateAssistantProfileTool::NAME => 'Готово, настройки ассистента сохранены.',
                SetTelegramResponseModeTool::NAME => $this->telegramModeFallback($result),
                CreateWatcherTool::NAME => $this->watcherCreatedFallback($result),
                default => $this->genericMutationFallback($result),
            };
        }

        $fromReads = $this->successfulReadFallback($results, $userText);

        if ($fromReads !== null) {
            return $fromReads;
        }

        if ($exception instanceof AiSafetyException) {
            return $this->safetyResponse($userText);
        }

        if ($exception instanceof AiEmptyResponseException) {
            return self::ANSWER_UNAVAILABLE;
        }

        return null;
    }

    private function isMutationResult(ToolResult $result): bool
    {
        if ($this->semantics !== null) {
            return $this->semantics->isMutation($result->name);
        }

        return in_array($result->name, self::KNOWN_MUTATIONS, true);
    }

    /**
     * @param  list<ToolResult>  $results
     */
    private function successfulReadFallback(array $results, ?string $userText): ?string
    {
        $askedWaiting = preg_match('/жду|ожида|waiting/u', mb_strtolower(trim((string) $userText))) === 1;

        if ($askedWaiting) {
            foreach (array_reverse($results) as $result) {
                if (! $result->success) {
                    continue;
                }

                $items = $this->waitingItems($result);

                if ($items !== null) {
                    return $this->waitingForReply($items);
                }
            }
        }

        foreach (array_reverse($results) as $result) {
            if (! $result->success) {
                continue;
            }

            $reply = $this->formatReadResult($result);

            if ($reply !== null) {
                return $reply;
            }
        }

        foreach ($results as $result) {
            if ($result->success) {
                return self::ANSWER_UNAVAILABLE;
            }
        }

        return null;
    }

    private function formatReadResult(ToolResult $result): ?string
    {
        $items = $this->waitingItems($result);

        if ($items !== null && in_array($result->name, [ListWaitingForTool::NAME, GetSynthesisTool::NAME], true)) {
            return $this->waitingForReply($items);
        }

        if ($result->name === ListCommitmentsTool::NAME) {
            return $this->commitmentsReply(is_array($result->payload['commitments'] ?? null) ? $result->payload['commitments'] : []);
        }

        $summary = trim((string) ($result->payload['summary'] ?? ''));

        if ($result->name === GetSynthesisTool::NAME && $summary !== '') {
            return $summary;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function waitingItems(ToolResult $result): ?array
    {
        if (! isset($result->payload['waiting_for']) || ! is_array($result->payload['waiting_for'])) {
            return null;
        }

        return $result->payload['waiting_for'];
    }

    /**
     * @param  list<mixed>  $items
     */
    private function waitingForReply(array $items): string
    {
        $lines = $this->itemLines($items);

        if ($lines === []) {
            return 'Сейчас нет открытых ожиданий.';
        }

        if (count($lines) === 1) {
            $line = $lines[0];

            return 'Сейчас вы ждёте: '.$line.(str_ends_with($line, '.') ? '' : '.');
        }

        return "Сейчас вы ждёте:\n— ".implode("\n— ", $lines);
    }

    /**
     * @param  list<mixed>  $items
     */
    private function commitmentsReply(array $items): string
    {
        $lines = $this->itemLines($items);

        if ($lines === []) {
            return 'Явных обязательств сейчас нет.';
        }

        if (count($lines) === 1) {
            return $lines[0].(str_ends_with($lines[0], '.') ? '' : '.');
        }

        return "Сейчас в обязательствах:\n— ".implode("\n— ", $lines);
    }

    /**
     * @param  list<mixed>  $items
     * @return list<string>
     */
    private function itemLines(array $items): array
    {
        $lines = [];

        foreach (array_slice($items, 0, 8) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            $why = trim((string) ($item['why'] ?? ''));

            if ($title === '') {
                continue;
            }

            $lines[] = ($why !== '' && $why !== $title) ? $title.'. '.$why : $title;
        }

        return $lines;
    }

    private function watcherCreatedFallback(ToolResult $result): string
    {
        $kind = (string) ($result->payload['kind'] ?? '');
        $description = trim((string) ($result->payload['confirm_as'] ?? $result->payload['description'] ?? ''));

        if ($kind === 'failed' || $result->success !== true) {
            $message = trim((string) ($result->payload['message'] ?? ''));

            return $message !== '' ? $message : 'Не получилось создать автоматизацию.';
        }

        if ($description !== '') {
            return str_starts_with($description, 'Готово') ? $description : 'Готово. '.$description;
        }

        return 'Готово. Поставлю автоматизацию на эту задачу.';
    }

    private function telegramModeFallback(ToolResult $result): string
    {
        return match ((string) ($result->payload['mode'] ?? '')) {
            'voice' => 'Готово. В Telegram буду отвечать голосом, когда это возможно.',
            'auto' => 'Готово. В Telegram включён автоматический режим ответа.',
            'text' => 'Готово. В Telegram буду отвечать текстом.',
            default => 'Готово. Режим ответа в Telegram обновлён.',
        };
    }

    private function genericMutationFallback(ToolResult $result): string
    {
        $text = trim((string) ($result->payload['text'] ?? $result->payload['message'] ?? $result->payload['description'] ?? ''));

        if ($text !== '') {
            return str_starts_with($text, 'Готово') ? $text : 'Готово. '.$text;
        }

        return 'Готово.';
    }

    private function safetyResponse(?string $userText): string
    {
        $text = mb_strtolower(trim((string) $userText));
        $meeting = preg_match('/встр(?:ет|ич)|meet(?:ing)?/u', $text) === 1;
        $onlineContact = preg_match('/интернет|онлайн|weplay|telegram|чат|фото|photo|online/u', $text) === 1;
        $minor = preg_match('/\b(?:1[0-7]|[5-9])\s*(?:лет|год|years?|yo)\b/u', $text) === 1;

        if ($meeting && ($onlineContact || $minor)) {
            return self::ONLINE_MEETING_RESPONSE;
        }

        return self::SAFETY_RESPONSE;
    }
}
