<?php

namespace App\Services\Voice\DTO;

use DateTimeInterface;

final readonly class VoiceAudioChunk
{
    public function __construct(
        public string $sessionPublicId,
        public int $sequence,
        public string $absolutePath,
        public int $byteLength,
        public string $mime,
        public ?int $sampleRate,
        public int $channels,
        public bool $isFinal,
        public ?int $durationMs,
        public ?DateTimeInterface $capturedAt,
        public string $profile = 'interactive',
    ) {}
}
