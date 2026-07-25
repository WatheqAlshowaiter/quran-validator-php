<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final class ProcessingResult
{
    /** @param list<DetectedQuote> $quotes */
    public function __construct(
        private readonly string $originalText,
        private readonly string $correctedText,
        private readonly array $quotes,
    ) {
    }

    /** Return the original processed text. */
    /** Return the original processed text. */
    public function originalText(): string
    {
        return $this->originalText;
    }

    /** Return the corrected text, or the original when unchanged. */
    public function correctedText(): string
    {
        return $this->correctedText;
    }

    /** @return list<DetectedQuote> */
    public function quotes(): array
    {
        return $this->quotes;
    }

    /** Return whether every quote is valid and unchanged. */
    public function allValid(): bool
    {
        foreach ($this->quotes as $quote) {
            if (!$quote->isValid() || $quote->wasCorrected()) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return [];
    }

    /** Return whether processing found invalid quotes. */
    public function hasErrors(): bool
    {
        foreach ($this->quotes as $quote) {
            if (!$quote->isValid()) {
                return true;
            }
        }

        return false;
    }
}
