<?php

namespace App\Services\Meetings\Exceptions;

use RuntimeException;

final class MeetingIntelligenceException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $error);
    }
}
