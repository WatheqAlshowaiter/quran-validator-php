<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final readonly class DetectionResult
{
    /** @param list<DetectionSegment> $segments */
    public function __construct(
        public bool $detected,
        public array $segments = [],
    ) {
    }
}
