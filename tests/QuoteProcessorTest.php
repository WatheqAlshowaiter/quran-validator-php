<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watheq\QuranValidator\QuoteProcessor;
use Watheq\QuranValidator\QuranValidator;

final class QuoteProcessorTest extends TestCase
{
    #[DataProvider('tagFormats')]
    public function testAllTagFormats(string $content, string $format): void
    {
        $result = (new QuoteProcessor(QuranValidator::fromDefaultDataset()))->process($content);
        self::assertCount(1, $result->quotes());
        self::assertSame($format, $result->quotes()[0]->format);
        self::assertTrue($result->quotes()[0]->isValid());
        self::assertFalse($result->hasErrors());
    }

    /** @return iterable<string, array{string, string}> */
    public static function tagFormats(): iterable
    {
        yield 'XML' => ['<quran ref="112:1">قل هو الله أحد</quran>', 'xml'];
        yield 'Markdown' => ["```quran ref=\"112:1\"\nقل هو الله أحد\n```", 'markdown'];
        yield 'Bracket' => ['[[Q:112:1|قل هو الله أحد]]', 'bracket'];
    }

    public function testInvalidQuoteIsReportedAndCorrectedFromKnownReference(): void
    {
        $content = 'Before <quran ref="1:1">بسم الله الكريم</quran> after';
        $result = (new QuoteProcessor(QuranValidator::fromDefaultDataset()))->process($content);

        self::assertTrue($result->hasErrors());
        self::assertTrue($result->quotes()[0]->wasCorrected());
        self::assertStringContainsString($this->canonical('1:1'), $result->correctedText());
        self::assertSame($content, $result->originalText());
    }

    public function testRangeQuote(): void
    {
        $validator = QuranValidator::fromDefaultDataset();
        $text = implode(' ', array_map(static fn ($verse): string => $verse->text, $validator->range('112:1-4')));
        $result = (new QuoteProcessor($validator))->process('<quran ref="112:1-4">'.$text.'</quran>');
        self::assertTrue($result->quotes()[0]->isValid());
    }

    private function canonical(string $reference): string
    {
        return QuranValidator::fromDefaultDataset()->verse($reference)->text;
    }
}
