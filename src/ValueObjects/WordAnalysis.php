<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final class WordAnalysis
{
    public function __construct(
        public readonly string $word,
        public readonly bool $fabricated,
    ) {
    }
}
