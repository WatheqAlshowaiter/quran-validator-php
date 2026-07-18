<?php

declare(strict_types=1);

namespace Watheq\QuranValidator;

use Watheq\QuranValidator\Contracts\ArabicNormalizerInterface;
use Watheq\QuranValidator\Contracts\QuranRepositoryInterface;
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

    public function __construct(
        private readonly QuranRepositoryInterface $repository,
        private readonly ArabicNormalizerInterface $normalizer,
        ?ValidatorOptions $options = null,
    ) {
        $this->options = $options ?? new ValidatorOptions();
    }

    public static function fromDefaultDataset(?ValidatorOptions $options = null): self
    {
        $normalizer = new ArabicNormalizer();
        $loader = new QuranDatasetLoader(
            dirname(__DIR__).'/data/quran-verses.min.json',
            dirname(__DIR__).'/data/quran-surahs.min.json',
        );

        return new self(new QuranRepository($loader, $normalizer), $normalizer, $options);
    }

    public function validate(string $text): ValidationResult
    {
        $text = trim($text);
        $normalized = $this->normalizer->normalize($text);
        if ($normalized === '' || preg_match('/\p{Arabic}/u', $text) !== 1) {
            return new ValidationResult(false, 'none', normalizedInput: $normalized);
        }

        $matches = $this->repository->exact($text);
        if ($matches !== []) {
            return $this->validResult($matches, 'exact', $normalized);
        }

        $matches = $this->repository->normalized($this->normalizer->normalizeForMatching($text));

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

        return $this->repository->verse($parsed)
            ?? throw new InvalidQuranReference(sprintf('Quran verse %s does not exist.', $reference));
    }

    /** @return list<QuranVerse> */
    public function range(string $reference): array
    {
        return $this->requireRange(QuranReference::parse($reference));
    }

    public function surah(int $number): ?QuranSurah
    {
        return $this->repository->surah($number);
    }

    /** @return list<SearchResult> */
    public function search(string $query, int $limit = 10): array
    {
        $query = $this->normalizer->normalizeForMatching(trim($query));
        if ($query === '' || $limit < 1) {
            return [];
        }

        $results = [];
        foreach ($this->repository->verses() as $verse) {
            $text = $this->repository instanceof QuranRepository
                ? $this->repository->normalizedText($verse)
                : $this->normalizer->normalize($verse->text);

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
            if ($this->repository instanceof QuranRepository) {
                for ($length = $count - $index; $length > 0; --$length) {
                    if ($this->repository->corpusContains(implode(' ', array_slice($matchingWords, $index, $length)))) {
                        $best = $length;
                        break;
                    }
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
        $verses = $this->repository->range($reference);
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
