<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final readonly class FabricationAnalysis
{
    /** @param list<WordAnalysis> $words */
    public function __construct(
        public string $normalizedInput,
        public array $words,
        public int $fabricatedWords,
    ) {
    }

    public function fabricatedRatio(): float
    {
        return $this->words === [] ? 0.0 : $this->fabricatedWords / count($this->words);
    }
}
