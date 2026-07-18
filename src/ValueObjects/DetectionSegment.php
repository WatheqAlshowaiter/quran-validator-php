<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final readonly class DetectionSegment
{
    public function __construct(
        public string $text,
        public int $start,
        public int $end,
        public ValidationResult $validation,
    ) {
    }
}
