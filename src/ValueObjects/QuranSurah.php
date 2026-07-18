<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final class QuranSurah
{
    public function __construct(
        public readonly int $number,
        public readonly string $name,
        public readonly string $englishName,
        public readonly int $versesCount,
        public readonly string $revelationType,
    ) {
    }
}
