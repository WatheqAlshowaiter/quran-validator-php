<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\Tests;

use PHPUnit\Framework\TestCase;
use Watheq\QuranValidator\ArabicNormalizer;

final class ArabicNormalizerTest extends TestCase
{
    public function testDiacriticsTatweelAnnotationsAlefAndWhitespace(): void
    {
        $normalizer = new ArabicNormalizer();
        self::assertSame('بسم الله', $normalizer->normalize(' بِسْمِـ   ٱللَّهِ ۞ '));
        self::assertSame('السلام عليكم', $normalizer->normalize('السَّلَامُ عَلَيْكُمُ'));
        self::assertSame('الله', $normalizer->normalize('ﷲ'));
    }

    public function testRemoveDiacriticsPreservesLetters(): void
    {
        self::assertSame('بسم الله', (new ArabicNormalizer())->removeDiacritics('بِسْمِ اللَّهِ'));
    }
}
