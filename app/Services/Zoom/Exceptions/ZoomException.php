<?php

namespace App\Services\Zoom\Exceptions;

use RuntimeException;
use Throwable;

final class ZoomException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message = '',
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $error, 0, $previous);
    }
}
