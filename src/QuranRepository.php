<?php

declare(strict_types=1);

namespace Watheq\QuranValidator;

use Watheq\QuranValidator\Contracts\ArabicNormalizerInterface;
use Watheq\QuranValidator\Contracts\QuranRepositoryInterface;
use Watheq\QuranValidator\Data\QuranDatasetLoader;
use Watheq\QuranValidator\ValueObjects\QuranReference;
use Watheq\QuranValidator\ValueObjects\QuranVerse;

final class QuranRepository implements QuranRepositoryInterface
{
    /** @var list<QuranVerse> */
    private array $verses;
    /** @var array<string, QuranVerse> */
    private array $referenceIndex = [];
    /** @var array<string, list<QuranVerse>> */
    private array $exactIndex = [];
    /** @var array<string, list<QuranVerse>> */
    private array $normalizedIndex = [];
    /** @var array<string, string> */
    private array $searchIndex = [];
    private int $surahCount;
    private string $corpus;

    public function __construct(QuranDatasetLoader $loader, ArabicNormalizerInterface $normalizer)
    {
        $data = $loader->load();
        $this->verses = $data['verses'];
        $this->surahCount = count($data['surahCounts']);
        $corpus = [];

        foreach ($this->verses as $verse) {
            $this->referenceIndex[$verse->reference()] = $verse;
            $this->exactIndex[$verse->text][] = $verse;
            $normalized = $normalizer->normalizeForMatching($verse->text);
            $this->normalizedIndex[$normalized][] = $verse;
            $simpleNormalized = $normalizer->normalizeForMatching($verse->simpleText);
            if ($simpleNormalized !== $normalized) {
                $this->normalizedIndex[$simpleNormalized][] = $verse;
            }
            $this->searchIndex[$verse->reference()] = $normalizer->normalizeForMatching($verse->simpleText);
            $corpus[] = $normalized;
        }

        $this->corpus = ' '.implode(' ', $corpus).' ';
    }

    public function exact(string $text): array
    {
        return $this->exactIndex[$text] ?? [];
    }

    public function normalized(string $text): array
    {
        return $this->normalizedIndex[$text] ?? [];
    }

    public function verse(QuranReference $reference): ?QuranVerse
    {
        return $this->referenceIndex[$reference->surah.':'.$reference->startAyah] ?? null;
    }

    public function range(QuranReference $reference): array
    {
        $verses = [];
        for ($ayah = $reference->startAyah; $ayah <= $reference->endAyah; ++$ayah) {
            $verse = $this->referenceIndex[$reference->surah.':'.$ayah] ?? null;
            if ($verse === null) {
                return [];
            }
            $verses[] = $verse;
        }

        return $verses;
    }

    public function verses(): array
    {
        return $this->verses;
    }

    public function surahCount(): int
    {
        return $this->surahCount;
    }

    public function normalizedText(QuranVerse $verse): string
    {
        return $this->searchIndex[$verse->reference()];
    }

    public function corpusContains(string $words): bool
    {
        return mb_strpos($this->corpus, ' '.$words.' ') !== false;
    }
}
