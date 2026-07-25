<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final class Difference
{
    public function __construct(
        public readonly string $input,
        public readonly string $correct,
        public readonly int $position,
    ) {
    }
}
