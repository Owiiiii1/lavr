<?php

namespace App\Services\Watchers;

final class ProactiveCheckIntent
{
    public static function userSelfReminder(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        return preg_match('/\bнапомни(?:те)?\b|\bremind(?:\s+me)?\b/u', $normalized) === 1;
    }

    public static function mentionsMail(string $text): bool
    {
        return preg_match('/почт|gmail|inbox|письм|email|e-mail|мейл/u', mb_strtolower($text)) === 1;
    }

    public static function isPeriodicDigest(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        return preg_match(
            '/кажд(?:ое|ый|ую)\s+утр|кажд(?:ый|ую)\s+день|по утрам|утренн\w*\s+сводк|присыл\w*.{0,32}сводк|сводк\w*.{0,32}(?:почт|письм|gmail)|every morning|every day at|daily.{0,16}(?:mail|gmail|inbox)|morning.{0,16}digest/u',
            $normalized,
        ) === 1;
    }

    public static function isGmailEventMonitoring(string $text): bool
    {
        if (self::userSelfReminder($text) || self::isPeriodicDigest($text)) {
            return false;
        }

        $normalized = mb_strtolower(trim($text));
        $extracted = GmailWatcherQuery::extractFromText($text);
        $hasTarget = ($extracted['senders'] ?? []) !== [] || ($extracted['sender_domains'] ?? []) !== [];

        $event = preg_match(
            '/жди\s+письм|ждать\s+письм|сообщ\w*.{0,48}когда.{0,32}прид|когда.{0,32}прид\w*.{0,32}письм|следи\s+за\s+письм|следить\s+за\s+письм|когда.{0,48}ответит|сразу\s+сообщ|как только.{0,48}письм|монитор\w*.{0,48}(?:письм|почт|gmail)|watch.{0,24}(?:mail|inbox|from)|notify.{0,24}when.{0,40}(?:mail|email)|кажд\w+\s+письм|о каждом письм/u',
            $normalized,
        ) === 1;

        if ($event) {
            return true;
        }

        return $hasTarget && preg_match('/следи|жди|сообщ|монитор|watch|notify|alert/u', $normalized) === 1;
    }

    public static function isGmailFilterAddon(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        return preg_match('/\b(?:и (?:ещё|еще|от|для)|тоже|плюс|добав)|ещё следи|еще следи|also watch|and also/u', $normalized) === 1;
    }

    public static function wantsOneShotMailAlert(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        return preg_match(
            '/перв(?:ое|ый)\s+письм|один ответ|жду один|как только (?:он|она|они) ответит|the first (?:mail|email|reply)|once.{0,16}replies/u',
            $normalized,
        ) === 1;
    }

    public static function jarvisPerformsCheck(string $text): bool
    {
        return self::isPeriodicDigest($text) || self::isGmailEventMonitoring($text);
    }

    public static function jarvisShouldMonitorMail(string $text): bool
    {
        return self::mentionsMail($text)
            && self::isPeriodicDigest($text)
            && ! self::userSelfReminder($text);
    }
}
