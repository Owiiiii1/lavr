<?php

namespace App\Services\Directory\Exceptions;

use RuntimeException;

final class DirectoryException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $error);
    }
}
