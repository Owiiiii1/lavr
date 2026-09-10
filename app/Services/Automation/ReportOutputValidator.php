<?php

namespace App\Services\Automation;

final class ReportOutputValidator
{
    public function rejectReason(string $text): ?string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return 'empty';
        }

        if (preg_match('/^\s*[\{\[]/u', $trimmed) === 1 || preg_match('/```(?:json)?/i', $trimmed) === 1) {
            return 'raw_json';
        }

        if (preg_match('/\b(stack trace|sqlstate|tool_call|function_call|provider=|api[_ ]key|exception:)\b/i', $trimmed) === 1) {
            return 'technical_markers';
        }

        if (preg_match('/запроси(те|ть) (меня )?выполнить|ask me to (run|query)|please run this query/iu', $trimmed) === 1) {
            return 'ask_to_query';
        }

        $lines = preg_split('/\R/u', $trimmed) ?: [];
        $subjectLike = 0;
        foreach ($lines as $line) {
            if (preg_match('/^\s*[-*•]\s+.+\s+—\s+.+$/u', $line) === 1) {
                $subjectLike++;
            }
        }
        if ($subjectLike >= 8 && mb_strlen($trimmed) > 400) {
            return 'subject_dump';
        }

        if (mb_strlen($trimmed) > 4000) {
            return 'too_long';
        }

        if (preg_match('/[,:;\-–—]$/u', $trimmed) === 1) {
            return 'truncated';
        }

        return null;
    }
}
