<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final class DetectedQuote
{
    public function __construct(
        public readonly string $text,
        public readonly string $reference,
        public readonly string $format,
        public readonly int $start,
        public readonly int $end,
        public readonly int $textStart,
        public readonly int $textEnd,
        public readonly ?ValidationResult $validation = null,
        public readonly ?string $correctedText = null,
        public readonly string $detectionMethod = 'tagged',
    ) {
    }

    public function isValid(): bool
    {
        return $this->validation?->isValid() ?? false;
    }

    public function wasCorrected(): bool
    {
        return $this->correctedText !== null && $this->correctedText !== $this->text;
    }
}
