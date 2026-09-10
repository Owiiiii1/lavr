<?php

namespace App\Services\Commitments\Exceptions;

use RuntimeException;

final class CommitmentException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $error);
    }
}
