<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final readonly class SearchResult
{
    public function __construct(
        public QuranVerse $verse,
        public float $score,
    ) {
    }
}
