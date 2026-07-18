<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\Contracts;

use Watheq\QuranValidator\ValueObjects\QuranReference;
use Watheq\QuranValidator\ValueObjects\QuranVerse;

interface QuranRepositoryInterface
{
    /** @return list<QuranVerse> */
    public function exact(string $text): array;

    /** @return list<QuranVerse> */
    public function normalized(string $text): array;

    public function verse(QuranReference $reference): ?QuranVerse;

    /** @return list<QuranVerse> */
    public function range(QuranReference $reference): array;

    /** @return list<QuranVerse> */
    public function verses(): array;

    public function surahCount(): int;
}
