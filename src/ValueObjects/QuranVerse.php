<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final class QuranVerse
{
    public function __construct(
        public readonly int $id,
        public readonly int $surah,
        public readonly int $ayah,
        public readonly string $text,
        public readonly string $simpleText,
    ) {
    }

    public function reference(): string
    {
        return $this->surah.':'.$this->ayah;
    }
}
