<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final class DetectionResult
{
    /** @param list<DetectionSegment> $segments */
    public function __construct(
        public readonly bool $detected,
        public readonly array $segments = [],
    ) {
    }
}
