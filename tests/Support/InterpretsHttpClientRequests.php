<?php

namespace Tests\Support;

trait InterpretsHttpClientRequests
{
    private function httpAuthorization(object $request): string
    {
        $header = $request->header('Authorization');
        if (is_array($header)) {
            return implode(' ', array_map(static fn (mixed $value): string => (string) $value, $header));
        }

        return (string) $header;
    }

    /**
     * @return array<string, mixed>
     */
    private function httpForm(object $request): array
    {
        if (! method_exists($request, 'data')) {
            return [];
        }

        $data = $request->data();

        return is_array($data) ? $data : [];
    }
}
