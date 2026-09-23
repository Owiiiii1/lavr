<?php

namespace App\Services\Telegram;

final class TelegramConversationMessages
{
    public const CONNECTED_WITH_CHAT = 'LAVR подключён. Текущий чат: «%s».';

    public const SELECT_CHAT = 'Выберите чат:';

    public const NO_CHATS = 'У вас пока нет чатов.';

    public const CHAT_SELECTED = 'Выбран чат «%s».';

    public const ENTER_NEW_CHAT_TITLE = 'Введите название нового чата.';

    public const CHAT_CREATED = 'Создан и выбран чат «%s».';

    public const CANCELLED = 'Отменено.';

    public const CURRENT_CHAT = 'Текущий чат: «%s».';

    public const INVALID_TITLE = 'Название чата должно быть от 1 до 120 символов.';

    public const CHAT_NOT_FOUND = 'Чат не найден.';

    public const MESSAGE_SAVED = 'Сообщение сохранено в чате «%s». AI будет подключён на следующем этапе.';

    public const AI_FAILURE = 'Не удалось получить ответ от AI. Попробуйте ещё раз позже.';

    public const LIST_TRUNCATED = 'Показаны последние 20 чатов.';

    public static function voiceTooLong(int $maxSeconds): string
    {
        return 'Голосовое слишком длинное. Максимальная длительность — '.self::durationLabel(max(1, $maxSeconds)).'.';
    }

    public const VOICE_TOO_LARGE = 'Голосовое слишком большое. Отправьте короче или напишите текстом.';

    public const VOICE_UNSUPPORTED = 'Этот формат голосового не поддерживается. Попробуйте ещё раз или напишите текстом.';

    public const VOICE_STT_UNAVAILABLE = 'Распознавание речи сейчас недоступно. Напишите текстом.';

    public const VOICE_STT_FAILED = 'Не удалось распознать голосовое. Попробуйте ещё раз или напишите текстом.';

    public const VOICE_EMPTY = 'Не удалось разобрать голосовое сообщение. Попробуйте ещё раз.';

    public const VOICE_DOWNLOAD_FAILED = 'Не удалось получить голосовое сообщение. Попробуйте ещё раз.';

    public static function connectedWithChat(string $title): string
    {
        return sprintf(self::CONNECTED_WITH_CHAT, $title);
    }

    public static function chatSelected(string $title): string
    {
        return sprintf(self::CHAT_SELECTED, $title);
    }

    public static function chatCreated(string $title): string
    {
        return sprintf(self::CHAT_CREATED, $title);
    }

    public static function currentChat(string $title): string
    {
        return sprintf(self::CURRENT_CHAT, $title);
    }

    public static function messageSaved(string $title): string
    {
        return sprintf(self::MESSAGE_SAVED, $title);
    }

    private static function durationLabel(int $seconds): string
    {
        if ($seconds < 60 || $seconds % 60 !== 0) {
            return $seconds.' '.self::plural($seconds, 'секунда', 'секунды', 'секунд');
        }

        $minutes = intdiv($seconds, 60);

        return $minutes.' '.self::plural($minutes, 'минута', 'минуты', 'минут');
    }

    private static function plural(int $value, string $one, string $few, string $many): string
    {
        $mod10 = $value % 10;
        $mod100 = $value % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $few;
        }

        return $many;
    }
}
