<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final class DetectedQuote
{
    /** Create a detected quote result. */
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
        public readonly bool $wasCorrected = false,
    ) {
    }

    /** Return the original quote text. */
    public function original(): string
    {
        return $this->text;
    }

    /** Return the corrected quote text, when available. */
    public function corrected(): string
    {
        return $this->correctedText ?? $this->text;
    }

    /** Return the normalized input used for validation. */
    public function normalizedInput(): ?string
    {
        return $this->validation?->normalizedInput;
    }

    /** Return the normalized expected text, when available. */
    public function expectedNormalized(): ?string
    {
        return $this->validation?->expectedNormalized;
    }

    /** Return whether the value represents valid Quran content. */
    public function isValid(): bool
    {
        return $this->validation?->isValid() ?? false;
    }

    /** Return whether automatic correction was applied. */
    public function wasCorrected(): bool
    {
        return $this->wasCorrected
            || ($this->correctedText !== null && $this->correctedText !== $this->text);
    }
}
