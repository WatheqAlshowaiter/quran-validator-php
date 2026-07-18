<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final readonly class DetectedQuote
{
    public function __construct(
        public string $text,
        public string $reference,
        public string $format,
        public int $start,
        public int $end,
        public int $textStart,
        public int $textEnd,
        public ?ValidationResult $validation = null,
        public ?string $correctedText = null,
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
