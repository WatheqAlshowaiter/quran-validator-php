<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final readonly class WordAnalysis
{
    public function __construct(
        public string $word,
        public bool $fabricated,
    ) {
    }
}
