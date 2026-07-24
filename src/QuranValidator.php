<?php

declare(strict_types=1);

namespace Watheq\QuranValidator;

use Watheq\QuranValidator\Contracts\ArabicNormalizerInterface;
use Watheq\QuranValidator\Data\QuranDatasetLoader;
use Watheq\QuranValidator\Exceptions\InvalidQuranReference;
use Watheq\QuranValidator\Exceptions\InvalidVerseRange;
use Watheq\QuranValidator\ValueObjects\DetectionResult;
use Watheq\QuranValidator\ValueObjects\DetectionSegment;
use Watheq\QuranValidator\ValueObjects\FabricationAnalysis;
use Watheq\QuranValidator\ValueObjects\QuranReference;
use Watheq\QuranValidator\ValueObjects\QuranSurah;
use Watheq\QuranValidator\ValueObjects\QuranVerse;
use Watheq\QuranValidator\ValueObjects\SearchResult;
use Watheq\QuranValidator\ValueObjects\ValidationResult;
use Watheq\QuranValidator\ValueObjects\ValidatorOptions;
use Watheq\QuranValidator\ValueObjects\WordAnalysis;

final class QuranValidator
{
    private readonly ValidatorOptions $options;

    /** @var list<QuranVerse> */
    private array $verses;

    /** @var array<string, QuranVerse> */
    private array $verseIndex = [];

    /** @var array<string, list<QuranVerse>> */
    private array $exactIndex = [];

    /** @var array<string, list<QuranVerse>> */
    private array $normalizedIndex = [];

    /** @var array<string, string> */
    private array $searchIndex = [];

    /** @var array<int, QuranSurah> */
    private array $surahIndex = [];

    private string $corpus;

    public function __construct(
        QuranDatasetLoader $loader,
        private readonly ArabicNormalizerInterface $normalizer,
        ?ValidatorOptions $options = null,
    ) {
        $this->options = $options ?? new ValidatorOptions();
        $data = $loader->load();
        $this->verses = $data['verses'];

        foreach ($data['surahs'] as $surah) {
            $this->surahIndex[$surah->number] = $surah;
        }

        $corpus = [];
        foreach ($this->verses as $verse) {
            $this->verseIndex[$verse->reference()] = $verse;
            $this->exactIndex[$verse->text][] = $verse;
            $normalized = $this->normalizer->normalizeForMatching($verse->text);
            $this->normalizedIndex[$normalized][] = $verse;
            $simpleNormalized = $this->normalizer->normalizeForMatching($verse->simpleText);
            if ($simpleNormalized !== $normalized) {
                $this->normalizedIndex[$simpleNormalized][] = $verse;
            }
            $this->searchIndex[$verse->reference()] = $simpleNormalized;
            $corpus[] = $normalized;
            if ($simpleNormalized !== $normalized) {
                $corpus[] = $simpleNormalized;
            }
        }

        $this->corpus = ' '.implode(' ', $corpus).' ';
    }

    public static function fromDefaultDataset(?ValidatorOptions $options = null): self
    {
        $normalizer = new ArabicNormalizer();
        $loader = new QuranDatasetLoader(
            dirname(__DIR__).'/data/quran-verses.min.json',
            dirname(__DIR__).'/data/quran-surahs.min.json',
        );

        return new self($loader, $normalizer, $options);
    }

    /**
     * Validate a potential Quran quote.
     *
     * @param string $text Arabic text to validate.
     *
     * @return ValidationResult Validation result with match details.
     */
    public function validate(string $text): ValidationResult
    {
        $text = trim($text);
        $normalized = $this->normalizer->normalize($text);
        if ($normalized === '' || preg_match('/\p{Arabic}/u', $text) !== 1) {
            return new ValidationResult(false, 'none', normalizedInput: $normalized);
        }

        $matches = $this->exactIndex[$text] ?? [];
        if ($matches !== []) {
            return $this->validResult($matches, 'exact', $normalized);
        }

        $matches = $this->normalizedIndex[$this->normalizer->normalizeForMatching($text)] ?? [];

        return $matches === []
            ? new ValidationResult(false, 'none', normalizedInput: $normalized)
            : $this->validResult($matches, 'normalized', $normalized);
    }

    public function validateReference(string $text, string $reference): ValidationResult
    {
        $parsed = QuranReference::parse($reference);
        $verses = $this->requireRange($parsed);
        $expected = implode(' ', array_map(static fn (QuranVerse $verse): string => $verse->text, $verses));
        $trimmed = trim($text);
        $normalized = $this->normalizer->normalize($trimmed);
        $expectedNormalized = $this->normalizer->normalize($expected);

        if ($trimmed === $expected) {
            return new ValidationResult(true, 'exact', $verses[0], (string) $parsed, $normalized, $expectedNormalized);
        }

        $matchingInput = $this->normalizer->normalizeForMatching($trimmed);
        $matchingExpected = $this->normalizer->normalizeForMatching($expected);
        if ($matchingInput === $matchingExpected) {
            return new ValidationResult(true, 'normalized', $verses[0], (string) $parsed, $normalized, $expectedNormalized);
        }

        return new ValidationResult(
            false,
            'none',
            reference: (string) $parsed,
            normalizedInput: $normalized,
            expectedNormalized: $expectedNormalized,
            mismatchIndex: $this->mismatchIndex($matchingInput, $matchingExpected),
        );
    }

    public function detectAndValidate(string $text): DetectionResult
    {
        $segments = [];
        $detected = false;

        foreach ($this->normalizer->extractArabicSegments($text) as $segment) {
            if (mb_strlen($segment->text) < $this->options->minDetectionLength) {
                continue;
            }

            $validation = $this->validate($segment->text);
            $segments[] = new DetectionSegment(
                $segment->text,
                $segment->start,
                $segment->end,
                $validation,
            );
            $detected = $detected || $validation->isValid();
        }

        return new DetectionResult($detected, $segments);
    }

    public function verse(string $reference): QuranVerse
    {
        $parsed = QuranReference::parse($reference);
        if ($parsed->isRange()) {
            throw new InvalidQuranReference('A single verse reference is required.');
        }

        return $this->verseIndex[$parsed->surah.':'.$parsed->startAyah]
            ?? throw new InvalidQuranReference(sprintf('Quran verse %s does not exist.', $reference));
    }

    /** @return list<QuranVerse> */
    public function range(string $reference): array
    {
        return $this->requireRange(QuranReference::parse($reference));
    }

    public function surah(int $number): ?QuranSurah
    {
        return $this->surahIndex[$number] ?? null;
    }

    /** @return list<SearchResult> */
    public function search(string $query, int $limit = 10): array
    {
        $query = $this->normalizer->normalizeForMatching(trim($query));
        if ($query === '' || $limit < 1) {
            return [];
        }

        $results = [];
        foreach ($this->verses as $verse) {
            $text = $this->searchIndex[$verse->reference()];

            if (mb_strpos($text, $query) !== false) {
                $results[] = new SearchResult($verse, 0.7 + (mb_strlen($query) / mb_strlen($text) * 0.3));
            }
        }

        usort($results, static fn (SearchResult $a, SearchResult $b): int => $b->score <=> $a->score);

        return array_slice($results, 0, $limit);
    }

    public function analyzeFabrication(string $text): FabricationAnalysis
    {
        $normalized = $this->normalizer->normalize($text);
        $matching = $this->normalizer->normalizeForMatching($text);
        $words = $normalized === '' ? [] : explode(' ', $normalized);
        $matchingWords = $matching === '' ? [] : explode(' ', $matching);
        $analyses = [];

        for ($index = 0, $count = count($matchingWords); $index < $count;) {
            $best = 0;
            for ($length = $count - $index; $length > 0; --$length) {
                $candidate = implode(' ', array_slice($matchingWords, $index, $length));
                if (mb_strpos($this->corpus, ' '.$candidate.' ') !== false) {
                    $best = $length;
                    break;
                }
            }

            if ($best === 0) {
                $analyses[] = new WordAnalysis($words[$index], true);
                ++$index;
                continue;
            }

            for ($offset = 0; $offset < $best; ++$offset) {
                $analyses[] = new WordAnalysis($words[$index + $offset], false);
            }
            $index += $best;
        }

        return new FabricationAnalysis(
            $normalized,
            $analyses,
            count(array_filter($analyses, static fn (WordAnalysis $word): bool => $word->fabricated)),
        );
    }

    /** @param list<QuranVerse> $matches */
    private function validResult(array $matches, string $type, string $normalized): ValidationResult
    {
        return new ValidationResult(
            true,
            $type,
            $matches[0],
            $matches[0]->reference(),
            $normalized,
            suggestions: array_slice($matches, 1, $this->options->maxSuggestions),
        );
    }

    /** @return list<QuranVerse> */
    private function requireRange(QuranReference $reference): array
    {
        $verses = [];
        for ($ayah = $reference->startAyah; $ayah <= $reference->endAyah; ++$ayah) {
            $verse = $this->verseIndex[$reference->surah.':'.$ayah] ?? null;
            if ($verse === null) {
                $verses = [];
                break;
            }
            $verses[] = $verse;
        }
        if ($verses === []) {
            throw new InvalidVerseRange(sprintf('Quran verse range %s does not exist.', $reference));
        }

        return $verses;
    }

    private function mismatchIndex(string $actual, string $expected): int
    {
        $length = min(mb_strlen($actual), mb_strlen($expected));
        for ($index = 0; $index < $length; ++$index) {
            if (mb_substr($actual, $index, 1) !== mb_substr($expected, $index, 1)) {
                return $index;
            }
        }

        return $length;
    }
}
