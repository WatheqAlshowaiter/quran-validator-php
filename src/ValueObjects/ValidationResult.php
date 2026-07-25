<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final class ValidationResult
{
    /** @param list<QuranVerse> $suggestions */
    public function __construct(
        private readonly bool $valid,
        private readonly string $matchType,
        private readonly ?QuranVerse $matchedVerse = null,
        private readonly ?string $reference = null,
        public readonly ?string $normalizedInput = null,
        public readonly ?string $expectedNormalized = null,
        public readonly ?int $mismatchIndex = null,
        public readonly array $suggestions = [],
        public readonly ?string $error = null,
    ) {
    }

    /** Return whether validation succeeded. */
    public function isValid(): bool
    {
        return $this->valid;
    }

    /** Return the validation match type. */
    public function matchType(): string
    {
        return $this->matchType;
    }

    /** Return the matched verse, when available. */
    public function matchedVerse(): ?QuranVerse
    {
        return $this->matchedVerse;
    }

    /** Return the matched reference, when available. */
    public function reference(): ?string
    {
        return $this->reference;
    }
}
