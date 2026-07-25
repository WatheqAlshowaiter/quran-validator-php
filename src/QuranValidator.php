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
use Watheq\QuranValidator\ValueObjects\FabricationStats;
use Watheq\QuranValidator\ValueObjects\NormalizeOptions;
use Watheq\QuranValidator\ValueObjects\QuranReference;
use Watheq\QuranValidator\ValueObjects\QuranSurah;
use Watheq\QuranValidator\ValueObjects\QuranVerse;

use Watheq\QuranValidator\ValueObjects\ValidationResult;
use Watheq\QuranValidator\ValueObjects\ValidatorOptions;
use Watheq\QuranValidator\ValueObjects\WordAnalysis;

/**
 * Validate and verify Quranic verses in text.
 *
 * Example:
 *
 *     $validator = QuranValidator::fromDefaultDataset();
 *     $result = $validator->validate('بِسْمِ ٱللَّهِ ٱلرَّحْمَٰنِ ٱلرَّحِيمِ');
 *     $result->isValid(); // true
 *     $result->reference(); // 1:1
 */
final class QuranValidator
{
    private readonly ValidatorOptions $options;
    private readonly ArabicNormalizerInterface $normalizer;

    /** @var list<QuranVerse> */
    private array $verses;

    /** @var list<QuranSurah> */
    private array $surahs;

    /** @var array<string, QuranVerse> */
    private array $verseIndex = [];

    /** @var array<string, QuranVerse> */
    private array $exactIndex = [];

    /** @var array<string, list<QuranVerse>> */
    private array $normalizedIndex = [];

    /** @var array<int, QuranSurah> */
    private array $surahIndex = [];

    private string $corpus;

    /** Create a validator using the bundled Quran dataset. */
    public function __construct(
        ?ValidatorOptions $options = null,
        ?QuranDatasetLoader $loader = null,
        ?ArabicNormalizerInterface $normalizer = null,
    ) {
        $this->options = $options ?? new ValidatorOptions();
        $this->normalizer = $normalizer ?? new ArabicNormalizer();
        $loader ??= new QuranDatasetLoader(
            dirname(__DIR__).'/data/quran-verses.min.json',
            dirname(__DIR__).'/data/quran-surahs.min.json',
        );
        $data = $loader->load();
        $this->verses = $data['verses'];
        $this->surahs = $data['surahs'];

        foreach ($this->surahs as $surah) {
            $this->surahIndex[$surah->number] = $surah;
        }

        $corpus = [];
        foreach ($this->verses as $verse) {
            $this->verseIndex[$verse->reference()] = $verse;
            $this->exactIndex[$verse->text] = $verse;

            $normalized = $this->normalizer->normalizeForMatching($verse->text);
            $this->normalizedIndex[$normalized][] = $verse;
            $corpus[] = $this->_normalizeFabrication($verse->text);

            $simpleNormalized = $this->normalizer->normalizeForMatching($verse->simpleText);
            if ($simpleNormalized !== $normalized) {
                $this->normalizedIndex[$simpleNormalized][] = $verse;
                $corpus[] = $this->_normalizeFabrication($verse->simpleText);
            }
        }

        $this->corpus = implode(' ', $corpus);
    }

    /** Create a validator with the default dataset and optional settings. */
    public static function fromDefaultDataset(?ValidatorOptions $options = null): self
    {
        return new self($options);
    }

    /**
     * Validate a potential Quran quote.
     *
     * @param  string  $text  Arabic text to validate.
     *
     * @return ValidationResult Validation result with match details.
     */
    public function validate(string $text): ValidationResult
    {
        $trimmed = trim($text);
        $normalized = $this->normalizer->normalize($trimmed);
        $lookupKey = $this->normalizer->normalizeForMatching($trimmed); // todo why not _normalizeFabrication?

        if (!$this->normalizer->containsArabic($trimmed)) {
            return $this->_noMatch($normalized);
        }

        $exact = $this->exactIndex[$trimmed] ?? null;
        if ($exact !== null) {
            return $this->_createResult($exact, 'exact', $normalized);
        }

        $matches = $this->normalizedIndex[$lookupKey] ?? [];
        if ($matches !== []) {
            return $this->_createResult(
                verse: $matches[0],
                type: 'normalized',
                normalized: $normalized,
                suggestions: count($matches) > 1 ? array_slice($matches, 0, $this->options->maxSuggestions) : [],
            );
        }

        return $this->_noMatch($normalized);
    }

    /**
     * Validate text against a specific verse or verse range.
     *
     * Invalid references return an invalid result instead of throwing.
     *
     * @param string $text Arabic text to validate.
     * @param string $reference Expected reference, for example 1:1 or 2:255-257.
     *
     * @return ValidationResult Result with match and diff information.
     */
    public function validateAgainst(string $text, string $reference): ValidationResult
    {
        $trimmed = trim($text);
        $normalized = $this->normalizer->normalize($trimmed);

        try {
            $parsed = QuranReference::parse($reference);
            $verses = $this->_requireRange($parsed);
        } catch (InvalidQuranReference|InvalidVerseRange) {
            return $this->_noMatch($normalized);
        }

        $expected = implode(' ', array_map(static fn (QuranVerse $verse): string => $verse->text, $verses));
        $expectedNormalized = $this->normalizer->normalize($expected);

        if ($trimmed === $expected) {
            return new ValidationResult(
                valid: true,
                matchType: 'exact',
                matchedVerse: $verses[0],
                reference: (string) $parsed,
                normalizedInput: $normalized,
                expectedNormalized: $expectedNormalized,
            );
        }
        $matchingInput = $this->normalizer->normalizeForMatching($trimmed);
        $matchingExpected = $this->normalizer->normalizeForMatching($expected);
        if ($matchingInput === $matchingExpected) {
            return new ValidationResult(
                valid: true,
                matchType: 'normalized',
                matchedVerse: $verses[0],
                reference: (string) $parsed,
                normalizedInput: $normalized,
                expectedNormalized: $expectedNormalized,
            );
        }
        return new ValidationResult(
            valid: false,
            matchType: 'none',
            reference: (string) $parsed,
            normalizedInput: $normalized,
            expectedNormalized: $expectedNormalized,
            mismatchIndex: $this->_mismatchIndex($matchingInput, $matchingExpected),
        );
    }

    /**
     * Detect and validate potential Quran quotations in text.
     *
     * @param string $text Text that may contain Quran quotations.
     *
     * @return DetectionResult Validated Arabic segments and detection status.
     */
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

    /** Get a verse by surah and ayah number. */
    public function getVerse(int $surah, int $ayah): ?QuranVerse
    {
        return $this->verseIndex[$surah.':'.$ayah] ?? null;
    }

    /**
     * Get a range of verses and concatenate their text.
     *
     * @return array{text: string, textSimple: string, verses: list<QuranVerse>}|null
     */
    public function getVerseRange(int $surah, int $startAyah, int $endAyah): ?array
    {
        if ($startAyah > $endAyah) {
            return null;
        }

        $verses = [];
        for ($ayah = $startAyah; $ayah <= $endAyah; ++$ayah) {
            $verse = $this->getVerse($surah, $ayah);
            if ($verse === null) {
                return null;
            }
            $verses[] = $verse;
        }

        return [
            'text' => implode(' ', array_map(static fn (QuranVerse $verse): string => $verse->text, $verses)),
            'textSimple' => implode(
                ' ',
                array_map(static fn (QuranVerse $verse): string => $verse->simpleText, $verses)
            ),
            'verses' => $verses,
        ];
    }

    /** @return list<QuranVerse> */
    public function getSurahVerses(int $surah): array
    {
        return array_values(array_filter(
            $this->verses,
            static fn (QuranVerse $verse): bool => $verse->surah === $surah,
        ));
    }

    /** Get surah information. */
    public function getSurah(int $number): ?QuranSurah
    {
        return $this->surahIndex[$number] ?? null;
    }

    /**
     * Get all surahs.
     *
     * @return list<QuranSurah>
     */
    public function getAllSurahs(): array
    {
        return $this->surahs;
    }

    /** Get one verse by a string reference. */
    public function verse(string $reference): QuranVerse
    {
        $parsed = QuranReference::parse($reference);
        if ($parsed->isRange()) {
            throw new InvalidQuranReference('A single verse reference is required.');
        }

        return $this->getVerse($parsed->surah, $parsed->startAyah)
            ?? throw new InvalidQuranReference(sprintf('Quran verse %s does not exist.', $reference));
    }

    /** @return list<QuranVerse> */
    public function range(string $reference): array
    {
        return $this->_requireRange(QuranReference::parse($reference));
    }

    /** Get surah metadata by number. */
    public function surah(int $number): ?QuranSurah
    {
        return $this->getSurah($number);
    }

    /**
     * Search verses by text using containment-based matching.
     *
     * Results contain the matching verse and similarity score, sorted by relevance.
     *
     * @param string $query Search text.
     * @param int $limit Maximum number of results.
     *
     * @return list<array{verse: QuranVerse, similarity: float}>
     */
    public function search(string $query, int $limit = 10): array
    {
        $normalizedQuery = $this->normalizer->normalizeForMatching(trim($query));
        if ($normalizedQuery === '' || $limit < 1) {
            return [];
        }

        $results = [];
        foreach ($this->verses as $verse) {
            $normalizedVerse = $this->normalizer->normalizeForMatching($verse->simpleText);

            if (mb_strpos($normalizedVerse, $normalizedQuery) !== false) {
                $ratio = mb_strlen($normalizedQuery) / mb_strlen($normalizedVerse);
                $results[] = ['verse' => $verse, 'similarity' => 0.7 + $ratio * 0.3];
            } elseif (mb_strpos($normalizedQuery, $normalizedVerse) !== false) {
                $ratio = mb_strlen($normalizedVerse) / mb_strlen($normalizedQuery);
                $results[] = ['verse' => $verse, 'similarity' => 0.5 + $ratio * 0.3];
            }
        }

        usort($results, static fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Analyze text for fabricated words that do not occur in the Quran corpus.
     *
     * Uses greedy longest contiguous matching after normalization.
     *
     * @param string $text Arabic text to analyze.
     *
     * @return FabricationAnalysis Word-level fabrication analysis and statistics.
     */
    public function analyzeFabrication(string $text): FabricationAnalysis
    {
        $normalized = $this->normalizer->normalize($text);
        $matching = $this->_normalizeFabrication($text);
        $words = $normalized === '' ? [] : explode(' ', $normalized);
        $matchingWords = $matching === '' ? [] : explode(' ', $matching);
        $analyses = [];
        if ($words === []) {
            return new FabricationAnalysis(
                normalizedInput: $normalized,
                words: [],
                fabricatedWords: 0,
                stats: new FabricationStats(0, 0, 0.0),
            );
        }

        for ($index = 0, $count = count($matchingWords); $index < $count;) {
            $low = 1;
            $high = $count - $index;
            $best = 0;

            while ($low <= $high) {
                // Binary search for longest contiguous match
                $middle = intdiv($low + $high, 2);
                $candidate = implode(' ', array_slice($matchingWords, $index, $middle));
                if (mb_strpos($this->corpus, $candidate) !== false) {
                    $best = $middle;
                    $low = $middle + 1;
                } else {
                    $high = $middle - 1;
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
        $fabricatedWords = count(array_filter($analyses, static fn (WordAnalysis $word): bool => $word->fabricated));
        return new FabricationAnalysis(
            normalizedInput: $normalized,
            words: $analyses,
            fabricatedWords: $fabricatedWords,
            stats: new FabricationStats(count($analyses), $fabricatedWords, $fabricatedWords / count($analyses)),
        );
    }

    /** @param  list<QuranVerse>  $suggestions */
    private function _createResult(
        QuranVerse $verse,
        string $type,
        string $normalized,
        array $suggestions = [],
    ): ValidationResult {
        return new ValidationResult(
            valid: true,
            matchType: $type,
            matchedVerse: $verse,
            reference: $verse->reference(),
            normalizedInput: $normalized,
            suggestions: $suggestions,
        );
    }

    /** Build a failed validation result. */
    private function _noMatch(?string $normalized = null): ValidationResult
    {
        return new ValidationResult(
            valid: false,
            matchType: 'none',
            normalizedInput: $normalized
        );
    }

    /** Aggressively normalize text for fabrication checking. */
    private function _normalizeFabrication(string $text): string
    {
        return $this->normalizer->normalize($text, new NormalizeOptions(stripHamza: true));
    }

    /** @return list<QuranVerse> */
    private function _requireRange(QuranReference $reference): array
    {
        $range = $this->getVerseRange($reference->surah, $reference->startAyah, $reference->endAyah);
        $verses = $range['verses'] ?? [];
        if ($verses === []) {
            throw new InvalidVerseRange(sprintf('Quran verse range %s does not exist.', $reference));
        }

        return $verses;
    }

    /** Return the first differing character index, or -1 when equal. */
    private function _mismatchIndex(string $actual, string $expected): int
    {
        $length = min(mb_strlen($actual), mb_strlen($expected));
        for ($index = 0; $index < $length; ++$index) {
            if (mb_substr($actual, $index, 1) !== mb_substr($expected, $index, 1)) {
                return $index;
            }
        }

        return mb_strlen($actual) === mb_strlen($expected) ? -1 : $length;
    }
}
