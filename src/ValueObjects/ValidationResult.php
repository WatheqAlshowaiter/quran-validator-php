<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final class ValidationResult
{
    private readonly MatchType $matchType;
    /** @param list<QuranVerse> $suggestions */
    public function __construct(
        private readonly bool $valid,
        MatchType|string $matchType,
        private readonly ?QuranVerse $matchedVerse = null,
        private readonly ?string $reference = null,
        public readonly ?string $normalizedInput = null,
        public readonly ?string $expectedNormalized = null,
        public readonly ?int $mismatchIndex = null,
        public readonly array $suggestions = [],
        public readonly ?string $error = null,
    ) {
        $this->matchType = $matchType instanceof MatchType ? $matchType : MatchType::from($matchType);
    }

    /** Return whether validation succeeded. */
    public function isValid(): bool
    {
        return $this->valid;
    }

    /** Return the validation match type. */
    public function matchType(): string
    {
        return $this->matchType->value;
    }

    /** Return the typed validation match type. */
    public function matchTypeEnum(): MatchType
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
