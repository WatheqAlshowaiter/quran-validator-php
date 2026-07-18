<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

/** Options for Arabic text normalization. */
final class NormalizeOptions
{
    public function __construct(
        public readonly bool $diacritics = true,
        public readonly bool $markers = true,
        public readonly bool $verseNumbers = true,
        public readonly bool $tatweel = true,
        public readonly bool $smallLetters = true,
        public readonly bool $punctuation = true,
        public readonly bool $collapseWhitespace = true,
        public readonly bool $stripHamza = false,
    ) {
    }
}
