<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final class FabricationStats
{
    public function __construct(
        public readonly int $totalWords,
        public readonly int $fabricatedWords,
        public readonly float $fabricatedRatio,
    ) {
    }
}
