<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\Contracts;

interface ArabicNormalizerInterface
{
    public function normalize(string $text): string;

    public function normalizeForMatching(string $text): string;

    public function removeDiacritics(string $text): string;
}
