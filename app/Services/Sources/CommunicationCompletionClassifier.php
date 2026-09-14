<?php

namespace App\Services\Sources;

final class CommunicationCompletionClassifier
{
    public function isStrongCompletion(string $text): bool
    {
        $haystack = mb_strtolower($text);

        if ($this->isWeak($haystack)) {
            return false;
        }

        return preg_match(
            '/\b(готово|отправил|відправив|во\s+вложен|вкладен|attached|final(?:\s+budget)?(?:\s+version)?|финальн|завантажив|загрузил)\b/u',
            $haystack,
        ) === 1;
    }

    public function isProgress(string $text): bool
    {
        $haystack = mb_strtolower($text);

        return preg_match('/\b(занимаюсь|майже готово|почти готово|постараюсь|almost ready|working on)\b/u', $haystack) === 1;
    }

    public function isWeak(string $text): bool
    {
        $haystack = mb_strtolower($text);

        return preg_match('/\b(занимаюсь|майже готово|почти готово|постараюсь вечером|almost ready)\b/u', $haystack) === 1
            && preg_match('/\b(готово|отправил|відправив|attached|финальн)\b/u', $haystack) !== 1;
    }
}
