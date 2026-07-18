<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final class DetectionSegment
{
    public function __construct(
        public readonly string $text,
        public readonly int $start,
        public readonly int $end,
        public readonly ValidationResult $validation,
    ) {
    }
}
