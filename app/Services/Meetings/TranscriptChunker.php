<?php

namespace App\Services\Meetings;

final class TranscriptChunker
{
    /**
     * @return list<string>
     */
    public function chunk(string $text, ?int $chunkChars = null, ?int $overlapChars = null, ?int $maxChunks = null): array
    {
        $text = trim($text);
        $limit = $chunkChars ?? MeetingConfig::chunkChars();
        $overlap = $overlapChars ?? MeetingConfig::chunkOverlapChars();
        $max = $maxChunks ?? MeetingConfig::maxChunks();

        if ($text === '') {
            return [];
        }

        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $lines = preg_split("/\n/", $text) ?: [$text];
        $chunks = [];
        $buffer = '';

        foreach ($lines as $line) {
            $candidate = $buffer === '' ? $line : $buffer."\n".$line;

            if (mb_strlen($candidate) <= $limit) {
                $buffer = $candidate;

                continue;
            }

            if ($buffer !== '') {
                $chunks[] = $buffer;
                $buffer = $this->overlapTail($buffer, $overlap);
                $buffer = $buffer === '' ? $line : $buffer."\n".$line;
            } else {
                $chunks[] = mb_substr($line, 0, $limit);
                $buffer = mb_substr($line, $limit);
            }
        }

        if (trim($buffer) !== '') {
            $chunks[] = $buffer;
        }

        if (count($chunks) <= $max) {
            return array_values(array_filter($chunks, static fn (string $chunk): bool => trim($chunk) !== ''));
        }

        $kept = array_slice($chunks, 0, $max - 1);
        $kept[] = implode("\n", array_slice($chunks, $max - 1));

        return array_values($kept);
    }

    private function overlapTail(string $text, int $overlap): string
    {
        if ($overlap < 1) {
            return '';
        }

        $tail = mb_substr($text, max(0, mb_strlen($text) - $overlap));
        $break = mb_strpos($tail, "\n");

        if ($break !== false) {
            $tail = mb_substr($tail, $break + 1);
        }

        return trim($tail);
    }
}
