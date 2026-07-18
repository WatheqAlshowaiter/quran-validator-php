<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final readonly class QuranSurah
{
    public function __construct(
        public int $number,
        public string $name,
        public string $englishName,
        public int $versesCount,
        public string $revelationType,
    ) {
    }
}
