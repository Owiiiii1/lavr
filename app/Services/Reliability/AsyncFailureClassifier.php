<?php

namespace App\Services\Reliability;

use App\Enums\AsyncFailureCategory;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiEmptyResponseException;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Exceptions\AiSafetyException;
use App\Services\Groups\Exceptions\GroupAnalysisException;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Knowledge\Exceptions\KnowledgeExtractionException;
use App\Services\Meetings\Exceptions\MeetingIntelligenceException;
use App\Services\Memory\Exceptions\MemoryAnalysisException;
use App\Services\Reliability\Exceptions\ClassifiedAsyncException;
use App\Services\Storage\Exceptions\StoredFileException;
use Error;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use InvalidArgumentException;
use JsonException;
use Throwable;
use TypeError;

final class AsyncFailureClassifier
{
    public function classify(?Throwable $exception): AsyncFailure
    {
        if ($exception === null) {
            return new AsyncFailure(AsyncFailureCategory::Unknown, 'job_failed', false);
        }

        if ($exception instanceof ClassifiedAsyncException) {
            return new AsyncFailure(
                $exception->category,
                $exception->failureCode !== '' ? $exception->failureCode : $exception->category->value,
                $exception->retryable,
                $exception::class,
            );
        }

        if ($exception instanceof TimeoutExceededException) {
            return new AsyncFailure(AsyncFailureCategory::ProviderTimeout, 'job_timeout', true, $exception::class);
        }

        if ($exception instanceof MaxAttemptsExceededException) {
            $previous = $exception->getPrevious();

            if ($previous instanceof Throwable) {
                return $this->classify($previous);
            }

            return new AsyncFailure(AsyncFailureCategory::Unknown, 'max_attempts', false, $exception::class);
        }

        if ($exception instanceof IntegrationException) {
            $haystack = mb_strtolower($exception->error.' '.$exception->getMessage());
            if (str_contains($haystack, 'not_connected') || str_contains($haystack, 'not connected') || str_contains($haystack, 'unauthorized') || str_contains($haystack, 'unauthenticated')) {
                return new AsyncFailure(AsyncFailureCategory::ProviderAuth, $exception->error !== '' ? $exception->error : 'provider_auth', false, $exception::class);
            }

            if ($exception->retryable) {
                return new AsyncFailure(AsyncFailureCategory::Network, $exception->error !== '' ? $exception->error : 'network', true, $exception::class);
            }

            return new AsyncFailure(AsyncFailureCategory::Validation, $exception->error !== '' ? $exception->error : 'integration', false, $exception::class);
        }

        if ($exception instanceof ModelNotFoundException) {
            return new AsyncFailure(AsyncFailureCategory::MissingSource, 'missing_source', false, $exception::class);
        }

        if ($exception instanceof AiSafetyException) {
            return new AsyncFailure(AsyncFailureCategory::ProviderSafety, 'provider_safety', false, $exception::class);
        }

        if ($exception instanceof AiEmptyResponseException) {
            return new AsyncFailure(AsyncFailureCategory::MalformedProviderResponse, 'empty_provider_response', true, $exception::class);
        }

        if ($exception instanceof AiConfigurationException) {
            return new AsyncFailure(AsyncFailureCategory::ProviderAuth, 'provider_config', false, $exception::class);
        }

        if ($exception instanceof MemoryAnalysisException || $exception instanceof GroupAnalysisException || $exception instanceof KnowledgeExtractionException || $exception instanceof MeetingIntelligenceException) {
            return new AsyncFailure(AsyncFailureCategory::MalformedProviderResponse, 'structured_output', false, $exception::class);
        }

        if ($exception instanceof StoredFileException) {
            return new AsyncFailure(AsyncFailureCategory::Validation, $exception->error !== '' ? $exception->error : 'validation', false, $exception::class);
        }

        if ($exception instanceof ConnectionException) {
            return new AsyncFailure(AsyncFailureCategory::Network, 'connection', true, $exception::class);
        }

        if ($exception instanceof RequestException) {
            return $this->fromHttpStatus($exception->response?->status(), $exception);
        }

        if ($exception instanceof QueryException) {
            return new AsyncFailure(AsyncFailureCategory::Database, 'query_exception', false, $exception::class);
        }

        if ($exception instanceof JsonException) {
            return new AsyncFailure(AsyncFailureCategory::Serialization, 'json', false, $exception::class);
        }

        if ($exception instanceof TypeError || $exception instanceof Error) {
            return new AsyncFailure(AsyncFailureCategory::CodeBug, 'code_bug', false, $exception::class);
        }

        if ($exception instanceof InvalidArgumentException) {
            return new AsyncFailure(AsyncFailureCategory::Validation, 'invalid_argument', false, $exception::class);
        }

        if ($exception instanceof AiProviderException) {
            return $this->fromProviderMessage($exception->getMessage(), $exception);
        }

        return $this->fromProviderMessage($exception->getMessage(), $exception);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function classifyStored(?string $lastError, ?array $metadata): AsyncFailure
    {
        $categoryValue = $metadata['error_category'] ?? null;

        if (is_string($categoryValue)) {
            $category = AsyncFailureCategory::tryFrom($categoryValue);

            if ($category !== null) {
                $retryable = array_key_exists('retryable', $metadata ?? [])
                    ? (bool) $metadata['retryable']
                    : $category->isTransient();
                $code = is_string($metadata['error_code'] ?? null) && $metadata['error_code'] !== ''
                    ? (string) $metadata['error_code']
                    : $category->value;
                $class = is_string($metadata['error_class'] ?? null) ? (string) $metadata['error_class'] : null;

                return new AsyncFailure($category, $code, $retryable, $class);
            }
        }

        return $this->fromProviderMessage((string) $lastError, null);
    }

    private function fromHttpStatus(?int $status, Throwable $exception): AsyncFailure
    {
        return match (true) {
            $status === 401, $status === 403 => new AsyncFailure(AsyncFailureCategory::ProviderAuth, 'http_'.$status, false, $exception::class),
            $status === 402 => new AsyncFailure(AsyncFailureCategory::ProviderQuota, 'http_402', false, $exception::class),
            $status === 408, $status === 504 => new AsyncFailure(AsyncFailureCategory::ProviderTimeout, 'http_'.$status, true, $exception::class),
            $status === 429 => new AsyncFailure(AsyncFailureCategory::ProviderRateLimit, 'http_429', true, $exception::class),
            $status !== null && $status >= 500 => new AsyncFailure(AsyncFailureCategory::ProviderUnavailable, 'http_'.$status, true, $exception::class),
            $status !== null && $status >= 400 => new AsyncFailure(AsyncFailureCategory::Validation, 'http_'.$status, false, $exception::class),
            default => $this->fromProviderMessage($exception->getMessage(), $exception),
        };
    }

    private function fromProviderMessage(string $message, ?Throwable $exception): AsyncFailure
    {
        $haystack = mb_strtolower($message);
        $class = $exception !== null ? $exception::class : null;

        if ($this->contains($haystack, ['safety policy', 'blocked by the provider safety', 'promptfeedback', 'finishreason safety'])) {
            return new AsyncFailure(AsyncFailureCategory::ProviderSafety, 'provider_safety', false, $class);
        }

        if ($this->contains($haystack, ['unauthenticated', 'unauthorized', 'invalid api key', 'api key not valid', 'permission denied', 'status 401', 'status 403', 'not_connected', 'not connected'])) {
            return new AsyncFailure(AsyncFailureCategory::ProviderAuth, 'provider_auth', false, $class);
        }

        if ($this->contains($haystack, ['quota', 'billing', 'insufficient', 'status 402'])) {
            return new AsyncFailure(AsyncFailureCategory::ProviderQuota, 'provider_quota', false, $class);
        }

        if ($this->contains($haystack, ['rate limit', 'resource exhausted', 'too many requests', 'status 429'])) {
            return new AsyncFailure(AsyncFailureCategory::ProviderRateLimit, 'provider_rate_limit', true, $class);
        }

        if ($this->contains($haystack, ['timed out', 'timeout', 'curl error 28', 'operation timed out', 'status 408', 'status 504'])) {
            return new AsyncFailure(AsyncFailureCategory::ProviderTimeout, 'provider_timeout', true, $class);
        }

        if ($this->contains($haystack, ['status 500', 'status 502', 'status 503', 'unavailable', 'overloaded'])) {
            return new AsyncFailure(AsyncFailureCategory::ProviderUnavailable, 'provider_unavailable', true, $class);
        }

        if ($this->contains($haystack, ['could not resolve', 'connection refused', 'failed to connect', 'network is unreachable', 'curl error'])) {
            return new AsyncFailure(AsyncFailureCategory::Network, 'network', true, $class);
        }

        if ($this->contains($haystack, ['empty assistant response', 'empty provider response', 'did not return a json', 'not a json object'])) {
            return new AsyncFailure(AsyncFailureCategory::MalformedProviderResponse, 'empty_provider_response', true, $class);
        }

        if ($this->contains($haystack, [
            'malformed',
            'must be an array',
            'must be an object',
            'unserialize',
            'serialization',
            'invalid json payload',
            'proto field is not repeating',
            'cannot start list',
            'unknown name "args"',
        ])) {
            return new AsyncFailure(AsyncFailureCategory::Serialization, 'serialization', false, $class);
        }

        if ($this->contains($haystack, ['sqlstate'])) {
            return new AsyncFailure(AsyncFailureCategory::Database, 'database', false, $class);
        }

        if ($this->contains($haystack, ['no such file', 'file not found', 'missing_source', 'stale_source'])) {
            $stale = str_contains($haystack, 'stale');

            return new AsyncFailure(
                $stale ? AsyncFailureCategory::StaleSource : AsyncFailureCategory::MissingSource,
                $stale ? 'stale_source' : 'missing_source',
                false,
                $class,
            );
        }

        if (preg_match('/status (4\d\d)/', $haystack, $matches) === 1) {
            return $this->fromHttpStatus((int) $matches[1], $exception ?? new AiProviderException($message));
        }

        if (preg_match('/status (5\d\d)/', $haystack, $matches) === 1) {
            return $this->fromHttpStatus((int) $matches[1], $exception ?? new AiProviderException($message));
        }

        return new AsyncFailure(AsyncFailureCategory::Unknown, 'unknown', false, $class);
    }

    /**
     * @param  list<string>  $needles
     */
    private function contains(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
