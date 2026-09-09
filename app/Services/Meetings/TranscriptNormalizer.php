<?php

namespace App\Services\Meetings;

final class TranscriptNormalizer
{
    public function __construct(
        private readonly SubtitleParser $subtitles = new SubtitleParser,
    ) {}

    /**
     * @return array{text: string, speakers: list<string>}
     */
    public function normalize(string $raw, string $extension): array
    {
        $extension = strtolower(trim($extension));
        $raw = preg_replace("/^\xEF\xBB\xBF/", '', $raw) ?? $raw;
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        return match ($extension) {
            'vtt' => $this->subtitles->parseVtt($raw),
            'srt' => $this->subtitles->parseSrt($raw),
            default => $this->normalizePlain($raw),
        };
    }

    /**
     * @return array{text: string, speakers: list<string>}
     */
    public function normalizePlain(string $raw): array
    {
        $lines = [];
        $speakers = [];

        foreach (explode("\n", $raw) as $line) {
            $line = rtrim($line);

            if (trim($line) === '') {
                $lines[] = '';

                continue;
            }

            $timestamp = null;
            $rest = $line;

            if (preg_match('/^\[([^\]]+)\]\s*(.*)$/u', $line, $matches) === 1) {
                $timestamp = trim($matches[1]);
                $rest = $matches[2];
            }

            $parsed = $this->subtitles->extractSpeaker($rest);
            $speakers[] = $parsed['speaker'];
            $prefix = $timestamp !== null ? '['.$timestamp.'] ' : '';
            $speakerLabel = $parsed['speaker'] !== '' ? $parsed['speaker'].': ' : '';
            $lines[] = $prefix.$speakerLabel.$parsed['text'];
        }

        $text = trim(implode("\n", $lines));

        return [
            'text' => $text,
            'speakers' => $this->uniqueSpeakers($speakers),
        ];
    }

    /**
     * @param  list<string>  $speakers
     * @return list<string>
     */
    private function uniqueSpeakers(array $speakers): array
    {
        $unique = [];

        foreach ($speakers as $speaker) {
            $name = trim($speaker);

            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name);

            if (! isset($unique[$key])) {
                $unique[$key] = $name;
            }
        }

        return array_values($unique);
    }
}
