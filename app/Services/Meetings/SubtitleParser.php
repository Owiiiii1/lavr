<?php

namespace App\Services\Meetings;

final class SubtitleParser
{
    /**
     * @return array{text: string, speakers: list<string>}
     */
    public function parseVtt(string $raw): array
    {
        $raw = preg_replace("/^\xEF\xBB\xBF/", '', $raw) ?? $raw;
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $blocks = preg_split("/\n{2,}/", trim($raw)) ?: [];
        $lines = [];
        $speakers = [];

        foreach ($blocks as $block) {
            $block = trim($block);

            if ($block === '' || str_starts_with($block, 'WEBVTT') || str_starts_with($block, 'NOTE') || str_starts_with($block, 'STYLE') || str_starts_with($block, 'REGION')) {
                continue;
            }

            $cueLines = explode("\n", $block);
            $timestamp = null;
            $textParts = [];

            foreach ($cueLines as $cueLine) {
                $cueLine = trim($cueLine);

                if ($cueLine === '' || preg_match('/^\d+$/', $cueLine) === 1) {
                    continue;
                }

                if (preg_match('/((?:\d{2}:)?\d{2}:\d{2}[.,]\d{3})\s+-->\s+/', $cueLine, $matches) === 1) {
                    $timestamp = $this->shortTimestamp($matches[1]);

                    continue;
                }

                $textParts[] = $cueLine;
            }

            $text = trim(implode(' ', $textParts));

            if ($text === '') {
                continue;
            }

            $parsed = $this->extractSpeaker($text);
            $speakers[] = $parsed['speaker'];
            $prefix = $timestamp !== null ? '['.$timestamp.'] ' : '';
            $speakerLabel = $parsed['speaker'] !== '' ? $parsed['speaker'].': ' : '';
            $lines[] = $prefix.$speakerLabel.$parsed['text'];
        }

        return [
            'text' => implode("\n", $lines),
            'speakers' => $this->uniqueSpeakers($speakers),
        ];
    }

    /**
     * @return array{text: string, speakers: list<string>}
     */
    public function parseSrt(string $raw): array
    {
        $raw = preg_replace("/^\xEF\xBB\xBF/", '', $raw) ?? $raw;
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $blocks = preg_split("/\n{2,}/", trim($raw)) ?: [];
        $lines = [];
        $speakers = [];

        foreach ($blocks as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            $cueLines = explode("\n", $block);
            $timestamp = null;
            $textParts = [];

            foreach ($cueLines as $cueLine) {
                $cueLine = trim($cueLine);

                if ($cueLine === '' || preg_match('/^\d+$/', $cueLine) === 1) {
                    continue;
                }

                if (preg_match('/((?:\d{2}:)?\d{2}:\d{2}[.,]\d{3})\s+-->\s+/', $cueLine, $matches) === 1) {
                    $timestamp = $this->shortTimestamp($matches[1]);

                    continue;
                }

                $textParts[] = $cueLine;
            }

            $text = trim(implode(' ', $textParts));

            if ($text === '') {
                continue;
            }

            $parsed = $this->extractSpeaker($text);
            $speakers[] = $parsed['speaker'];
            $prefix = $timestamp !== null ? '['.$timestamp.'] ' : '';
            $speakerLabel = $parsed['speaker'] !== '' ? $parsed['speaker'].': ' : '';
            $lines[] = $prefix.$speakerLabel.$parsed['text'];
        }

        return [
            'text' => implode("\n", $lines),
            'speakers' => $this->uniqueSpeakers($speakers),
        ];
    }

    /**
     * @return array{speaker: string, text: string}
     */
    public function extractSpeaker(string $text): array
    {
        $text = trim($text);

        if (preg_match('/^<v(?:oice)?\s+([^>]+)>(.*)$/is', $text, $matches) === 1) {
            return [
                'speaker' => trim(strip_tags($matches[1])),
                'text' => trim(strip_tags($matches[2])),
            ];
        }

        $stripped = strip_tags($text);

        if (preg_match('/^([^:]{1,80}):\s*(.+)$/u', $stripped, $matches) === 1) {
            $candidate = trim($matches[1]);

            if ($this->looksLikeSpeaker($candidate)) {
                return [
                    'speaker' => $candidate,
                    'text' => trim($matches[2]),
                ];
            }
        }

        return [
            'speaker' => '',
            'text' => $stripped,
        ];
    }

    public function looksLikeSpeaker(string $candidate): bool
    {
        if ($candidate === '' || mb_strlen($candidate) > 80) {
            return false;
        }

        if (preg_match('/^\d+$/', $candidate) === 1) {
            return false;
        }

        if (preg_match('/^(https?|www\.|http)/i', $candidate) === 1) {
            return false;
        }

        return (bool) preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .\'\-#]{0,79}$/u', $candidate);
    }

    private function shortTimestamp(string $value): string
    {
        $value = str_replace(',', '.', $value);

        if (preg_match('/(?:(\d{2}):)?(\d{2}):(\d{2})/', $value, $matches) !== 1) {
            return $value;
        }

        $hours = $matches[1] !== '' ? $matches[1] : '00';

        return $hours.':'.$matches[2].':'.$matches[3];
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
