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

    public function originalText(): string
    {
        return $this->originalText;
    }

    public function correctedText(): string
    {
        return $this->correctedText;
    }

    /** @return list<DetectedQuote> */
    public function quotes(): array
    {
        return $this->quotes;
    }

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
