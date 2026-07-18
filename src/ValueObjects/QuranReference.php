<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

use Watheq\QuranValidator\Exceptions\InvalidQuranReference;
use Watheq\QuranValidator\Exceptions\InvalidVerseRange;

final class QuranReference
{
    public function __construct(
        public readonly int $surah,
        public readonly int $startAyah,
        public readonly int $endAyah,
    ) {
        if ($surah < 1 || $surah > 114 || $startAyah < 1 || $endAyah < 1) {
            throw new InvalidQuranReference('Quran references must contain positive surah and ayah numbers within surahs 1-114.');
        }

        if ($startAyah > $endAyah) {
            throw new InvalidVerseRange('A verse range cannot end before it starts.');
        }
    }

    public static function parse(string $reference): self
    {
        if (preg_match('/^(\d{1,3}):(\d{1,3})(?:-(\d{1,3}))?$/D', $reference, $matches) !== 1) {
            throw new InvalidQuranReference(sprintf('Invalid Quran reference "%s".', $reference));
        }

        return new self(
            (int) $matches[1],
            (int) $matches[2],
            isset($matches[3]) ? (int) $matches[3] : (int) $matches[2],
        );
    }

    public function isRange(): bool
    {
        return $this->startAyah !== $this->endAyah;
    }

    public function __toString(): string
    {
        return $this->surah.':'.$this->startAyah.($this->isRange() ? '-'.$this->endAyah : '');
    }
}
