<?php

namespace App\Services\Zoom;

use App\Services\Meetings\MeetingConfig;
use App\Services\Zoom\Exceptions\ZoomException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ZoomTranscriptClient
{
    /**
     * @return array{download_url: string, meeting_topic: ?string, meeting_id: ?string, host_id: ?string, can_download: bool, restriction: ?string}
     */
    public function fetchTranscriptMetadata(string $accessToken, string $meetingUuid): array
    {
        $encoded = $this->encodeMeetingId($meetingUuid);
        $url = ZoomConfig::apiBaseUrl().'/meetings/'.$encoded.'/transcript';

        try {
            $response = Http::connectTimeout(ZoomConfig::connectTimeout())
                ->timeout(ZoomConfig::timeout())
                ->withToken($accessToken)
                ->acceptJson()
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new ZoomException('network', 'Zoom transcript metadata request failed.', true, previous: $exception);
        }

        $this->throwIfHttpFailed($response, 'transcript_metadata');

        $canDownload = (bool) $response->json('can_download');
        $restriction = $response->json('download_restriction_reason');
        $downloadUrl = trim((string) $response->json('download_url'));

        if (! $canDownload || $downloadUrl === '') {
            $reason = is_string($restriction) ? $restriction : 'NOT_READY';

            if (in_array($reason, ['NOT_READY', ''], true) || $restriction === null) {
                throw new ZoomException('transcript_unavailable', 'Zoom transcript is not ready yet.', true);
            }

            throw new ZoomException('transcript_unavailable', 'Zoom transcript is unavailable.', false);
        }

        return [
            'download_url' => $downloadUrl,
            'meeting_topic' => $this->nullableString($response->json('meeting_topic')),
            'meeting_id' => $this->nullableString($response->json('meeting_id')),
            'host_id' => $this->nullableString($response->json('host_id')),
            'can_download' => true,
            'restriction' => null,
        ];
    }

    public function download(string $accessToken, string $downloadUrl): string
    {
        $this->assertTrustedDownloadUrl($downloadUrl);

        try {
            $response = Http::connectTimeout(ZoomConfig::connectTimeout())
                ->timeout(ZoomConfig::downloadTimeout())
                ->withToken($accessToken)
                ->withOptions([
                    'allow_redirects' => false,
                    'http_errors' => false,
                ])
                ->get($downloadUrl);
        } catch (ConnectionException $exception) {
            throw new ZoomException('network', 'Zoom transcript download failed.', true, previous: $exception);
        }

        if ($response->redirect()) {
            $location = trim((string) $response->header('Location'));
            $this->assertTrustedDownloadUrl($location);

            try {
                $response = Http::connectTimeout(ZoomConfig::connectTimeout())
                    ->timeout(ZoomConfig::downloadTimeout())
                    ->withToken($accessToken)
                    ->withOptions([
                        'allow_redirects' => false,
                        'http_errors' => false,
                    ])
                    ->get($location);
            } catch (ConnectionException $exception) {
                throw new ZoomException('network', 'Zoom transcript download redirect failed.', true, previous: $exception);
            }
        }

        $this->throwIfHttpFailed($response, 'transcript_download');

        $body = (string) $response->body();

        if ($body === '') {
            throw new ZoomException('transcript_unavailable', 'Zoom transcript download was empty.', true);
        }

        if (strlen($body) > MeetingConfig::maxFileSizeBytes()) {
            throw new ZoomException('invalid_download', 'Zoom transcript exceeds the maximum size.', false);
        }

        $contentType = strtolower((string) $response->header('Content-Type'));

        if ($contentType !== '' && ! $this->isAllowedContentType($contentType)) {
            throw new ZoomException('invalid_download', 'Zoom transcript content type is not allowed.', false);
        }

        if (str_starts_with(ltrim($body), '<?') || str_starts_with($body, "\x7fELF")) {
            throw new ZoomException('invalid_download', 'Zoom transcript content is not a text transcript.', false);
        }

        Log::info('zoom transcript downloaded', [
            'bytes' => strlen($body),
            'host' => parse_url($downloadUrl, PHP_URL_HOST),
        ]);

        return $body;
    }

    public function assertTrustedDownloadUrl(string $url): void
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ZoomException('untrusted_download', 'Zoom transcript URL is invalid.', false);
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            throw new ZoomException('untrusted_download', 'Zoom transcript URL is not HTTPS.', false);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new ZoomException('untrusted_download', 'Zoom transcript URL host is not allowed.', false);
        }

        foreach (ZoomConfig::trustedDownloadHosts() as $allowed) {
            if ($host === $allowed) {
                return;
            }
        }

        foreach (ZoomConfig::trustedDownloadHostSuffixes() as $suffix) {
            if ($suffix !== '' && Str::endsWith($host, $suffix)) {
                return;
            }
        }

        throw new ZoomException('untrusted_download', 'Zoom transcript URL host is not allowed.', false);
    }

    public function encodeMeetingId(string $meetingId): string
    {
        return rawurlencode(rawurlencode($meetingId));
    }

    private function throwIfHttpFailed(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        if ($response->status() === 401) {
            throw new ZoomException('blocked_auth', 'Zoom rejected the access token.', false);
        }

        if ($response->status() === 429) {
            $header = $response->header('Retry-After');
            $retryAfter = is_numeric($header) ? max(1, (int) $header) : 60;

            throw new ZoomException('rate_limited', 'Zoom rate limited the '.$operation.' request.', true, $retryAfter);
        }

        if ($response->status() === 404) {
            throw new ZoomException('transcript_unavailable', 'Zoom transcript was not found.', true);
        }

        if ($response->serverError()) {
            throw new ZoomException('network', 'Zoom '.$operation.' failed.', true);
        }

        throw new ZoomException('zoom_api_failed', 'Zoom '.$operation.' failed.', false);
    }

    private function isAllowedContentType(string $contentType): bool
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));

        return in_array($type, [
            'text/plain',
            'text/vtt',
            'text/srt',
            'text/markdown',
            'application/octet-stream',
            'application/x-subrip',
        ], true);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
