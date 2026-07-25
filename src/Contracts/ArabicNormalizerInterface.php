<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\Contracts;

use Watheq\QuranValidator\ValueObjects\ArabicSegment;
use Watheq\QuranValidator\ValueObjects\Difference;
use Watheq\QuranValidator\ValueObjects\NormalizeOptions;

interface ArabicNormalizerInterface
{
    /** Normalize Arabic text using the supplied options. */
    public function normalize(string $text, ?NormalizeOptions $options = null): string;

    /** Normalize Quran-specific spelling and spacing variants. */
    public function normalizeForMatching(string $text): string;

    /** Remove Arabic diacritical marks. */
    public function removeDiacritics(string $text): string;

    /** Determine whether text contains Arabic characters. */
    public function containsArabic(string $text): bool;

    /** @return list<ArabicSegment> */
    public function extractArabicSegments(string $text): array;

    /** Calculate Unicode-safe Levenshtein similarity between two strings. */
    public function calculateSimilarity(string $first, string $second): float;

    /** @return list<Difference> */
    public function findDifferences(string $input, string $correct): array;
}
