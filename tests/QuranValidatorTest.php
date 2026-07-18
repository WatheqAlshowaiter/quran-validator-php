<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watheq\QuranValidator\ArabicNormalizer;
use Watheq\QuranValidator\Data\QuranDatasetLoader;
use Watheq\QuranValidator\Exceptions\DatasetFileMissing;
use Watheq\QuranValidator\Exceptions\InvalidQuranReference;
use Watheq\QuranValidator\Exceptions\InvalidUtf8;
use Watheq\QuranValidator\QuoteProcessor;
use Watheq\QuranValidator\QuranRepository;
use Watheq\QuranValidator\QuranValidator;

final class QuranValidatorTest extends TestCase
{
    private QuranValidator $validator;

    protected function setUp(): void
    {
        $this->validator = QuranValidator::fromDefaultDataset();
    }

    public function testDatasetIntegrity(): void
    {
        $normalizer = new ArabicNormalizer();
        $repository = new QuranRepository(new QuranDatasetLoader(
            dirname(__DIR__).'/data/quran-verses.min.json',
            dirname(__DIR__).'/data/quran-surahs.min.json',
        ), $normalizer);

        self::assertCount(6236, $repository->verses());
        self::assertSame(114, $repository->surahCount());
        self::assertSame('114:6', $repository->verses()[6235]->reference());
    }

    public function testMissingDatasetFailsClearly(): void
    {
        $this->expectException(DatasetFileMissing::class);
        (new QuranDatasetLoader('/missing/verses.json', '/missing/surahs.json'))->load();
    }

    public function testExactAndNormalizedWholeVerseMatching(): void
    {
        $verse = $this->validator->verse('1:1');
        $exact = $this->validator->validate($verse->text);
        $normalized = $this->validator->validate('بسم الله الرحمن الرحيم');

        self::assertTrue($exact->isValid());
        self::assertSame('exact', $exact->matchType());
        self::assertSame('1:1', $exact->reference());
        self::assertTrue($normalized->isValid());
        self::assertSame('normalized', $normalized->matchType());
        self::assertSame('1:1', $normalized->reference());
    }

    #[DataProvider('knownVerses')]
    public function testReferenceRegressions(string $text, string $reference): void
    {
        $result = $this->validator->validate($text);
        self::assertTrue($result->isValid());
        self::assertSame($reference, $result->reference());
    }

    /** @return iterable<string, array{string, string}> */
    public static function knownVerses(): iterable
    {
        yield 'Ikhlas' => ['قل هو الله أحد', '112:1'];
        yield 'Falaq' => ['قل أعوذ برب الفلق', '113:1'];
        yield 'Nas' => ['قل أعوذ برب الناس', '114:1'];
    }

    public function testInvalidAndPartialTextDoNotMatch(): void
    {
        self::assertFalse($this->validator->validate('مرحبا كيف حالك')->isValid());
        self::assertFalse($this->validator->validate('بسم الله')->isValid());
        self::assertFalse($this->validator->validate('')->isValid());
    }

    public function testReferenceValidationAndMismatchDetails(): void
    {
        $verse = $this->validator->verse('2:255');
        self::assertTrue($this->validator->validateReference($verse->text, '2:255')->isValid());

        $invalid = $this->validator->validateReference('الله لا إله إلا هو الكريم', '2:255');
        self::assertFalse($invalid->isValid());
        self::assertSame('2:255', $invalid->reference());
        self::assertNotNull($invalid->expectedNormalized);
        self::assertNotNull($invalid->mismatchIndex);
    }

    public function testLookupAndRange(): void
    {
        self::assertSame('2:255', $this->validator->verse('2:255')->reference());
        $range = $this->validator->range('112:1-4');
        self::assertCount(4, $range);
        self::assertSame('112:4', $range[3]->reference());
    }

    public function testInvalidReferencesThrow(): void
    {
        $this->expectException(InvalidQuranReference::class);
        $this->validator->verse('115:1');
    }

    public function testSearchUsesArabicNormalization(): void
    {
        $results = $this->validator->search('الحي القيوم', 5);
        self::assertNotEmpty($results);
        self::assertContains('2:255', array_map(static fn ($result): string => $result->verse->reference(), $results));
        self::assertLessThanOrEqual(5, count($this->validator->search('الله', 5)));
        self::assertSame([], $this->validator->search(''));
    }

    public function testFabricationAnalysis(): void
    {
        $analysis = $this->validator->analyzeFabrication('بسم الله الفلان');
        self::assertCount(3, $analysis->words);
        self::assertFalse($analysis->words[0]->fabricated);
        self::assertTrue($analysis->words[2]->fabricated);
        self::assertSame(1, $analysis->fabricatedWords);
    }

    public function testInvalidUtf8Throws(): void
    {
        $this->expectException(InvalidUtf8::class);
        $this->validator->validate("\xB1\x31");
    }
}
