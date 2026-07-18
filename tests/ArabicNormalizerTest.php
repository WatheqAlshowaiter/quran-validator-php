<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\Tests;

use PHPUnit\Framework\TestCase;
use Watheq\QuranValidator\ArabicNormalizer;
use Watheq\QuranValidator\ValueObjects\NormalizeOptions;

final class ArabicNormalizerTest extends TestCase
{
    public function testDiacriticsTatweelAnnotationsAlefAndWhitespace(): void
    {
        $normalizer = new ArabicNormalizer();
        self::assertSame('بسم الله', $normalizer->normalize(' بِسْمِـ   ٱللَّهِ ۞ '));
        self::assertSame('السلام عليكم', $normalizer->normalize('السَّلَامُ عَلَيْكُمُ'));
    }

    public function testNormalizationOptionsCanPreserveCharacters(): void
    {
        $normalizer = new ArabicNormalizer();

        self::assertSame('أَ', $normalizer->normalize('أَ', new NormalizeOptions(diacritics: false)));
        self::assertSame('۞', $normalizer->normalize('۞', new NormalizeOptions(markers: false, smallLetters: false)));
        self::assertSame('١', $normalizer->normalize('١', new NormalizeOptions(verseNumbers: false)));
        self::assertSame('ـ', $normalizer->normalize('ـ', new NormalizeOptions(tatweel: false)));
        self::assertSame('،', $normalizer->normalize('،', new NormalizeOptions(punctuation: false)));
        self::assertSame(
            '  بسم   الله  ',
            $normalizer->normalize('  بسم   الله  ', new NormalizeOptions(collapseWhitespace: false)),
        );
    }

    public function testMarkersAreRemovedWhenSmallLettersAreEnabled(): void
    {
        self::assertSame(
            '',
            (new ArabicNormalizer())->normalize('۞', new NormalizeOptions(markers: false)),
        );
    }

    public function testStripHamzaEnablesMatchingNormalization(): void
    {
        self::assertSame(
            'ي اة ي بسط سيطر ال',
            (new ArabicNormalizer())->normalize(
                'أإئء ى واة يي بصط صيطر الل',
                new NormalizeOptions(stripHamza: true),
            ),
        );
    }

    public function testRemoveDiacriticsPreservesLetters(): void
    {
        $normalizer = new ArabicNormalizer();

        self::assertSame('بسم الله', $normalizer->removeDiacritics('بِسْمِ اللَّهِ'));
        self::assertSame('ا،  ', $normalizer->removeDiacritics('آَ،  '));
    }

    public function testPreservesHamzaFormsAndNormalizesAlefVariants(): void
    {
        self::assertSame('أإاا', (new ArabicNormalizer())->normalize('أإآٱ'));
    }

    public function testPreservesAlefMaqsura(): void
    {
        self::assertSame('على', (new ArabicNormalizer())->normalize('على'));
    }

    public function testPreservesTehMarbuta(): void
    {
        self::assertSame('رحمة', (new ArabicNormalizer())->normalize('رحمة'));
    }

    public function testRemovesTatweel(): void
    {
        self::assertSame('كتاب', (new ArabicNormalizer())->normalize('كتـــاب'));
    }

    public function testNormalizesWhitespace(): void
    {
        self::assertSame('بسم الله', (new ArabicNormalizer())->normalize('بسم   الله'));
    }

    public function testHandlesAlefWasla(): void
    {
        self::assertSame('الله', (new ArabicNormalizer())->normalize('ٱللَّهُ'));
    }

    public function testNormalizesPresentationFormLigature(): void
    {
        $normalizer = new ArabicNormalizer();
        $result = $normalizer->normalize("\u{FDF2}");

        self::assertSame('الله', $result);
        self::assertStringNotContainsString("\u{FDF2}", $result);
        self::assertSame('لا', $normalizer->normalize("\u{FEFB}"));
    }

    public function testStripsBidiControlsAndVerseNumbers(): void
    {
        $result = (new ArabicNormalizer())->normalize("سورة \u{200F}١١٢:١ \u{200F}قُلْ هُوَ ٱللَّهُ أَحَدٌ");

        self::assertStringContainsString('سورة', $result);
        self::assertStringContainsString('قل هو الله أحد', $result);
        self::assertStringNotContainsString("\u{200C}", $result);
        self::assertStringNotContainsString("\u{200D}", $result);
        self::assertStringNotContainsString("\u{200E}", $result);
        self::assertStringNotContainsString("\u{200F}", $result);
        self::assertStringNotContainsString("\u{061C}", $result);
    }

    public function testNormalizesAlefWaslaVariant(): void
    {
        self::assertSame('الصدقات', (new ArabicNormalizer())->normalize('ٱلصَّدَقَـٰتُ'));
    }

    public function testRemovesRubElHizb(): void
    {
        $result = (new ArabicNormalizer())->normalize('۞ إِنَّمَا');

        self::assertStringNotContainsString('۞', $result);
        self::assertSame('إنما', $result);
    }

    public function testConvertsSuperscriptAlefToRegularAlef(): void
    {
        self::assertSame('السماوات', (new ArabicNormalizer())->normalize('ٱلسَّمَٰوَٰتِ'));
    }

    public function testDoesNotAddAlefForHamzaBeforeAlef(): void
    {
        self::assertSame('الاخر', (new ArabicNormalizer())->normalize('ٱلْـَٔاخِرِ'));
    }

    public function testRemovesAllDiacriticalMarks(): void
    {
        $result = (new ArabicNormalizer())->removeDiacritics('بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ');

        self::assertStringNotContainsString('ِ', $result);
        self::assertStringNotContainsString('ْ', $result);
        self::assertStringNotContainsString('َ', $result);
    }

    public function testContainsArabic(): void
    {
        $normalizer = new ArabicNormalizer();

        self::assertTrue($normalizer->containsArabic('مرحبا'));
        self::assertTrue($normalizer->containsArabic('Hello مرحبا world'));
        self::assertFalse($normalizer->containsArabic('Hello world'));
        self::assertFalse($normalizer->containsArabic('123'));
    }

    public function testExtractsArabicSegments(): void
    {
        $segments = (new ArabicNormalizer())->extractArabicSegments('Say بسم الله and continue');

        self::assertNotEmpty($segments);
        self::assertStringContainsString('بسم الله', $segments[0]->text);
    }
}
