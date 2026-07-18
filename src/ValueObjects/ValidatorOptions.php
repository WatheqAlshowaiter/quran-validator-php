<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final class ValidatorOptions
{
    public function __construct(
        public readonly int $maxSuggestions = 3,
        public readonly int $minDetectionLength = 10,
    ) {
    }
}
