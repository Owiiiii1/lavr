<?php

namespace App\Services\Groups;

use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Models\Message;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;

final class GroupAnalysisTranscriptFormatter
{
    /**
     * @param  Collection<int, Message>  $messages
     * @return list<array{id: int, line: string, chars: int}>
     */
    public function lines(TelegramGroup $group, Collection $messages, DateTimeZone $timezone): array
    {
        $rows = [];

        foreach ($messages as $message) {
            $line = $this->line($message, $timezone);
            $rows[] = [
                'id' => (int) $message->id,
                'line' => $line,
                'chars' => mb_strlen($line),
            ];
        }

        return $rows;
    }

    public function line(Message $message, DateTimeZone $timezone): string
    {
        $occurred = $message->occurred_at instanceof \DateTimeInterface
            ? CarbonImmutable::instance($message->occurred_at)->setTimezone($timezone)->format('Y-m-d H:i')
            : '';
        $isBot = $message->role === MessageRole::Assistant
            || (($message->metadata['group_outbound'] ?? false) === true);
        $sender = $isBot ? 'LAVR' : (trim((string) $message->sender_name) !== '' ? (string) $message->sender_name : 'Unknown');
        $username = $isBot ? null : $message->sender_username;
        $body = $this->body($message);
        $parts = [
            'id='.$message->id,
            'occurred='.$occurred,
            'sender='.$sender,
        ];

        if (filled($username)) {
            $parts[] = 'username='.$username;
        }

        $parts[] = 'is_bot='.($isBot ? 'true' : 'false');

        if (filled($message->thread_id)) {
            $parts[] = 'thread='.$message->thread_id;
        }

        if (filled($message->reply_to_channel_message_id)) {
            $parts[] = 'reply_to='.$message->reply_to_channel_message_id;
        }

        return '['.implode(' ', $parts).'] '.$body;
    }

    public function body(Message $message): string
    {
        $text = trim((string) $message->body);
        $type = $message->message_type;

        if ($type === MessageType::Text) {
            return $text;
        }

        $label = match ($type) {
            MessageType::Photo => 'photo',
            MessageType::Voice => 'voice message',
            MessageType::Audio => 'audio',
            MessageType::Video => 'video',
            MessageType::Document => 'document',
            MessageType::Sticker => 'sticker',
            MessageType::Location => 'location',
            MessageType::Contact => 'contact',
            MessageType::Poll => 'poll',
            default => $type->value,
        };

        if ($text === '') {
            return '['.$label.']';
        }

        return '['.$label.': '.$text.']';
    }
}
