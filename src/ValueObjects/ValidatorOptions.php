<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final readonly class ValidatorOptions
{
    public function __construct(
        public int $maxSuggestions = 3,
        public int $minDetectionLength = 10,
    ) {
    }
}
