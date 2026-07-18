<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\Contracts;

use Watheq\QuranValidator\ValueObjects\ArabicSegment;
use Watheq\QuranValidator\ValueObjects\NormalizeOptions;

interface ArabicNormalizerInterface
{
    public function normalize(string $text, ?NormalizeOptions $options = null): string;

    public function normalizeForMatching(string $text): string;

    public function removeDiacritics(string $text): string;

    public function containsArabic(string $text): bool;

    /** @return list<ArabicSegment> */
    public function extractArabicSegments(string $text): array;
}
