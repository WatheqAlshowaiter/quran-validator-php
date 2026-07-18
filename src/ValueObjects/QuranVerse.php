<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

final readonly class QuranVerse
{
    public function __construct(
        public int $id,
        public int $surah,
        public int $ayah,
        public string $text,
        public string $simpleText,
    ) {
    }

    public function reference(): string
    {
        return $this->surah.':'.$this->ayah;
    }
}
