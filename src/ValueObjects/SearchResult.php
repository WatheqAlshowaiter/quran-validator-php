<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final class SearchResult
{
    public function __construct(
        public readonly QuranVerse $verse,
        public readonly float $score,
    ) {
    }
}
