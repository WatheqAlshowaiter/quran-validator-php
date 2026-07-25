<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final class FabricationAnalysis
{
    /** @param list<WordAnalysis> $words */
    public function __construct(
        public readonly string $normalizedInput,
        public readonly array $words,
        public readonly int $fabricatedWords,
        public readonly FabricationStats $stats,
    ) {
    }

    /** Return the fraction of words marked as fabricated. */
    public function fabricatedRatio(): float
    {
        return $this->stats->fabricatedRatio;
    }
}
