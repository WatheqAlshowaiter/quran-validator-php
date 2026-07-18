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
        yield 'Ikhlas Uthmani' => ['قُلْ هُوَ ٱللَّهُ أَحَدٌ', '112:1'];
        yield 'Falaq Uthmani' => ['قُلْ أَعُوذُ بِرَبِّ ٱلْفَلَقِ', '113:1'];
        yield 'Nas Uthmani' => ['قُلْ أَعُوذُ بِرَبِّ ٱلنَّاسِ', '114:1'];
    }

    public function testInvalidAndPartialTextDoNotMatch(): void
    {
        self::assertFalse($this->validator->validate('مرحبا كيف حالك')->isValid());
        self::assertFalse($this->validator->validate('بسم الله')->isValid());
        self::assertFalse($this->validator->validate('')->isValid());
    }

    // Duplicate: testRejectPartialVerses() is covered by testInvalidAndPartialTextDoNotMatch().
    // Duplicate: testEmptyString() is covered by testInvalidAndPartialTextDoNotMatch().
    // Duplicate: testCommonAlefInsteadOfAlefWasla() is covered by testReferenceRegressions() ("Ikhlas").

    public function testNonArabicText(): void
    {
        $result = $this->validator->validate('Hello world');

        self::assertFalse($result->isValid());
        self::assertSame('none', $result->matchType());
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

    public function testNormalizedDiffForNoMatch(): void
    {
        $result = $this->validator->validate('بسم الله الرحمن');

        self::assertFalse($result->isValid());
        self::assertSame('بسم الله الرحمن', $result->normalizedInput);
    }

    public function testDiffAgainstReference(): void
    {
        $result = $this->validator->validateReference('بسم الله', '1:1');

        self::assertFalse($result->isValid());
        self::assertSame('بسم الله', $result->normalizedInput);
        self::assertSame('بسم الله الرحمان الرحيم', $result->expectedNormalized);
    }

    public function testMismatchPositions(): void
    {
        $result = $this->validator->validateReference('بسم الله الكريم الرحيم', '1:1');

        self::assertFalse($result->isValid());
        self::assertNotNull($result->mismatchIndex);
        self::assertGreaterThan(0, $result->mismatchIndex);
    }

    public function testLookupAndRange(): void
    {
        $verse = $this->validator->verse('1:1');
        self::assertSame(1, $verse->surah);
        self::assertSame(1, $verse->ayah);
        self::assertStringContainsString('بِسْمِ', $verse->text);

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

    // Duplicate: testGetBySurahAyah() is covered by testLookupAndRange().
    // Duplicate: testInvalidReference() is covered by testInvalidReferencesThrow().
    // Not ported: testGetSurah(), testInvalidSurah(), and testAlBaqaraCount() require a public surah metadata API.

    public function testSearchUsesArabicNormalization(): void
    {
        $results = $this->validator->search('الحي القيوم', 5);
        self::assertNotEmpty($results);
        self::assertContains('2:255', array_map(static fn ($result): string => $result->verse->reference(), $results));

        $normalizedResults = $this->validator->search('الله الرحمان');
        self::assertNotEmpty($normalizedResults);
        self::assertGreaterThan(0.3, $normalizedResults[0]->score);

        self::assertLessThanOrEqual(5, count($this->validator->search('الله', 5)));
        self::assertSame([], $this->validator->search(''));
        self::assertSame([], $this->validator->search('   '));
    }

    // Duplicate: testSearchVerses(), testLimitResults(), testEmptyQuery(), and testWhitespaceQuery()
    // are covered by testSearchUsesArabicNormalization().

    public function testFabricationAnalysis(): void
    {
        $analysis = $this->validator->analyzeFabrication('بسم الله الفلان');
        self::assertCount(3, $analysis->words);
        self::assertFalse($analysis->words[0]->fabricated);
        self::assertTrue($analysis->words[2]->fabricated);
        self::assertSame(1, $analysis->fabricatedWords);
    }

    // Duplicate: testFabricatedWords() is covered by testFabricationAnalysis().

    #[DataProvider('validFabricationTexts')]
    public function testValidFabricationTexts(string $text, int $wordCount): void
    {
        $analysis = $this->validator->analyzeFabrication($text);

        self::assertCount($wordCount, $analysis->words);
        self::assertSame(0, $analysis->fabricatedWords);
    }

    /** @return iterable<string, array{string, int}> */
    public static function validFabricationTexts(): iterable
    {
        yield 'real Quranic sequence' => ['بسم الله الرحمان الرحيم', 4];
        yield 'individual valid words' => ['الله السماء الأرض', 3];
        yield 'normalized input' => ['بِسْمِ اللَّهِ', 2];
        yield 'contiguous matches' => ['قل هو الله احد', 4];
        yield 'superscript alef' => ['وَالْمَسَاٰكِينِ', 1];
        yield 'full superscript alef fragment' => ['إِنَّمَا الصَّدَقَاتُ لِلْفُقَرَاءِ وَالْمَسَاٰكِينِ', 4];
        yield 'sad variant' => ['بَصْطَةً', 1];
        yield 'sin variant' => ['يَبْسُطُ', 1];
    }

    public function testEmptyFabricationAnalysis(): void
    {
        $analysis = $this->validator->analyzeFabrication('');

        self::assertSame([], $analysis->words);
        self::assertSame(0, $analysis->fabricatedWords);
        self::assertSame(0.0, $analysis->fabricatedRatio());
    }

    public function testCompletelyFabricatedText(): void
    {
        $analysis = $this->validator->analyzeFabrication('الفلان البلان الكلان');

        self::assertCount(3, $analysis->words);
        self::assertSame(3, $analysis->fabricatedWords);
        self::assertSame(1.0, $analysis->fabricatedRatio());
    }

    public function testFabricationNormalizedInput(): void
    {
        $analysis = $this->validator->analyzeFabrication('بِسْمِ اللَّهِ');

        self::assertSame('بسم الله', $analysis->normalizedInput);
    }

    #[DataProvider('uthmaniVariants')]
    public function testUthmaniVariants(string $reference, string $original, string $variant): void
    {
        $verse = $this->validator->verse($reference);
        $text = str_replace($original, $variant, $verse->text, $replacements);
        self::assertSame(1, $replacements);

        $result = $this->validator->validate($text);
        self::assertTrue($result->isValid());
        self::assertSame($reference, $result->reference());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function uthmaniVariants(): iterable
    {
        yield '2:247 sad variant' => ['2:247', 'بَسْطَةً', 'بَصْطَةً'];
        yield '7:69 sin variant' => ['7:69', 'بَصْۜطَةً', 'بَسْطَةً'];
        yield '2:245 sin variant' => ['2:245', 'وَيَبْصُۜطُ', 'وَيَبْسُطُ'];
        yield '52:37 sin variant' => ['52:37', 'ٱلْمُصَۣيْطِرُونَ', 'ٱلْمُسَيْطِرُونَ'];
        yield '88:22 sin variant' => ['88:22', 'بِمُصَيْطِرٍ', 'بِمُسَيْطِرٍ'];
        yield '2:33 spaced Adam' => ['2:33', 'يَـٰٓـَٔادَمُ', 'يَا آدَمُ'];
        yield '2:33 spaced Adam with hamza' => ['2:33', 'يَـٰٓـَٔادَمُ', 'يَا ءَادَمُ'];
        yield '2:21 spaced ayyuha' => ['2:21', 'يَـٰٓأَيُّهَا', 'يَا أَيُّهَا'];
        yield '2:21 without superscript alef' => ['2:21', 'يَـٰٓأَيُّهَا', 'يَـأَيُّهَا'];
    }

    public function testInvalidUtf8Throws(): void
    {
        $this->expectException(InvalidUtf8::class);
        $this->validator->validate("\xB1\x31");
    }

    public function testValidateBismallahUthmani(): void
    {
        $result = $this->validator->validate('بِسْمِ ٱللَّهِ ٱلرَّحْمَٰنِ ٱلرَّحِيمِ');
        self::assertTrue($result->isValid());
        self::assertSame('1:1', $result->reference());
    }

    public function testValidateAyatAlKursi(): void
    {
        $ayatAlKursi = 'ٱللَّهُ لَآ إِلَٰهَ إِلَّا هُوَ ٱلْحَىُّ ٱلْقَيُّومُ ۚ '
            .'لَا تَأْخُذُهُۥ سِنَةٌۭ وَلَا نَوْمٌۭ ۚ لَّهُۥ مَا فِى ٱلسَّمَٰوَٰتِ '
            .'وَمَا فِى ٱلْأَرْضِ ۗ مَن ذَا ٱلَّذِى يَشْفَعُ عِندَهُۥٓ إِلَّا بِإِذْنِهِۦ ۚ '
            .'يَعْلَمُ مَا بَيْنَ أَيْدِيهِمْ وَمَا خَلْفَهُمْ ۖ وَلَا يُحِيطُونَ بِشَىْءٍۢ '
            .'مِّنْ عِلْمِهِۦٓ إِلَّا بِمَا شَآءَ ۚ وَسِعَ كُرْسِيُّهُ ٱلسَّمَٰوَٰتِ وَٱلْأَرْضَ ۖ '
            .'وَلَا يَـُٔودُهُۥ حِفْظُهُمَا ۚ وَهُوَ ٱلْعَلِىُّ ٱلْعَظِيمُ';

        $result = $this->validator->validate($ayatAlKursi);

        self::assertTrue($result->isValid());
        self::assertSame('2:255', $result->reference());
    }

    public function testNonQuranArabic(): void
    {
        $result = $this->validator->validate('مرحبا كيف حالك اليوم');

        self::assertFalse($result->isValid());
        self::assertSame('none', $result->matchType());
    }

    public function testDetectInMixedText(): void
    {
        $result = $this->validator->detectAndValidate(
            'The verse بِسْمِ ٱللَّهِ ٱلرَّحْمَٰنِ ٱلرَّحِيمِ means "In the name of Allah"',
        );

        self::assertTrue($result->detected);
        self::assertNotEmpty($result->segments);
        self::assertTrue($result->segments[0]->validation->isValid());
    }

    public function testDetectWithNoArabicContent(): void
    {
        $result = $this->validator->detectAndValidate('This is just English text with no Arabic');

        self::assertFalse($result->detected);
        self::assertSame([], $result->segments);
    }

    public function testDetectMultipleSegments(): void
    {
        $result = $this->validator->detectAndValidate(
            'First verse: بِسْمِ ٱللَّهِ ٱلرَّحْمَٰنِ ٱلرَّحِيمِ and another: قُلْ هُوَ ٱللَّهُ أَحَدٌ',
        );

        self::assertTrue($result->detected);
        self::assertCount(2, $result->segments);
    }

    public function testDetectSkipsShortText(): void
    {
        $result = $this->validator->detectAndValidate('Just الله here');

        self::assertFalse($result->detected);
        self::assertSame([], $result->segments);
    }

    // Not ported: the PHP API does not expose a configurable validator factory.
    //
    //class TestCreateValidator:
    //def test_create_with_custom_options(self):
    //from quran_validator.types import ValidatorOptions
    //v = create_validator(ValidatorOptions(max_suggestions=5))
    //assert isinstance(v, QuranValidator)
}
