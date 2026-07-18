<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final readonly class ValidationResult
{
    /** @param list<QuranVerse> $suggestions */
    public function __construct(
        private bool $valid,
        private string $matchType,
        private ?QuranVerse $matchedVerse = null,
        private ?string $reference = null,
        public ?string $normalizedInput = null,
        public ?string $expectedNormalized = null,
        public ?int $mismatchIndex = null,
        public array $suggestions = [],
        public ?string $error = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function matchType(): string
    {
        return $this->matchType;
    }

    public function matchedVerse(): ?QuranVerse
    {
        return $this->matchedVerse;
    }

    public function reference(): ?string
    {
        return $this->reference;
    }
}
