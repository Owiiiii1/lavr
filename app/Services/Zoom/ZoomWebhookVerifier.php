<?php

namespace App\Services\Zoom;

use App\Services\Zoom\Exceptions\ZoomException;
use Illuminate\Http\Request;

final class ZoomWebhookVerifier
{
    public function assertBodySize(Request $request): string
    {
        $headerLength = (int) $request->headers->get('Content-Length', 0);

        if ($headerLength > ZoomConfig::maxWebhookBytes()) {
            throw new ZoomException('payload_too_large', 'Zoom webhook body is too large.', false);
        }

        $raw = $request->getContent();

        if (strlen($raw) > ZoomConfig::maxWebhookBytes()) {
            throw new ZoomException('payload_too_large', 'Zoom webhook body is too large.', false);
        }

        return $raw;
    }

    public function verify(Request $request, string $rawBody, string $secret): void
    {
        if ($secret === '') {
            throw new ZoomException('blocked_auth', 'Zoom webhook secret is not configured.', false);
        }

        $timestamp = trim((string) $request->header('x-zm-request-timestamp', ''));
        $signature = trim((string) $request->header('x-zm-signature', ''));
        $event = $this->peekEvent($rawBody);
        $isCrc = $event === 'endpoint.url_validation';

        if ($timestamp === '' || $signature === '') {
            if ($isCrc) {
                return;
            }

            throw new ZoomException('invalid_signature', 'Zoom webhook signature is missing.', false);
        }

        if (! ctype_digit($timestamp)) {
            throw new ZoomException('invalid_signature', 'Zoom webhook timestamp is invalid.', false);
        }

        $age = abs(time() - (int) $timestamp);

        if ($age > ZoomConfig::replayWindowSeconds()) {
            throw new ZoomException('expired_timestamp', 'Zoom webhook timestamp is outside the replay window.', false);
        }

        $expected = 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$rawBody, $secret);

        if (! hash_equals($expected, $signature)) {
            throw new ZoomException('invalid_signature', 'Zoom webhook signature is invalid.', false);
        }
    }

    public function encryptedToken(string $plainToken, string $secret): string
    {
        return hash_hmac('sha256', $plainToken, $secret);
    }

    public function assertAccountId(mixed $payloadAccountId, string $configuredAccountId): void
    {
        if ($configuredAccountId === '') {
            return;
        }

        $received = is_string($payloadAccountId) ? trim($payloadAccountId) : '';

        if ($received === '' || ! hash_equals($configuredAccountId, $received)) {
            throw new ZoomException('wrong_account', 'Zoom webhook account does not match the configured account.', false);
        }
    }

    private function peekEvent(string $rawBody): string
    {
        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? trim((string) ($decoded['event'] ?? '')) : '';
    }
}
