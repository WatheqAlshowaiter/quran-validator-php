<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\Contracts;

use Watheq\QuranValidator\ValueObjects\ArabicSegment;

interface ArabicNormalizerInterface
{
    public function normalize(string $text): string;

    public function normalizeForMatching(string $text): string;

    public function removeDiacritics(string $text): string;

    public function containsArabic(string $text): bool;

    /** @return list<ArabicSegment> */
    public function extractArabicSegments(string $text): array;
}
