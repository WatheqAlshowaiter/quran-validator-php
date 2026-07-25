<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watheq\QuranValidator\Exceptions\InvalidUtf8;
use Watheq\QuranValidator\LlmIntegration;
use Watheq\QuranValidator\QuranValidator;
use Watheq\QuranValidator\ValueObjects\LlmIntegrationOptions;

final class QuoteProcessorTest extends TestCase
{
    #[DataProvider('tagFormats')]
    public function testAllTagFormats(string $content, string $format): void
    {
        $result = (new LlmIntegration(QuranValidator::fromDefaultDataset()))->process($content);
        self::assertCount(1, $result->quotes());
        self::assertSame($format, $result->quotes()[0]->format);
        self::assertSame('tagged', $result->quotes()[0]->detectionMethod);
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
        $result = (new LlmIntegration(QuranValidator::fromDefaultDataset()))->process($content);

        self::assertCount(1, $result->quotes());
        self::assertFalse($result->quotes()[0]->isValid());
        self::assertTrue($result->hasErrors());
        self::assertTrue($result->quotes()[0]->wasCorrected());
        self::assertStringContainsString($this->canonical(), $result->correctedText());
        self::assertSame($content, $result->originalText());
    }

    public function testInvalidReferenceIsReported(): void
    {
        $result = (new LlmIntegration(QuranValidator::fromDefaultDataset()))
            ->process('<quran ref="115:1">نص عربي</quran>');

        self::assertCount(1, $result->quotes());
        self::assertFalse($result->quotes()[0]->isValid());
        self::assertNotNull($result->quotes()[0]->validation?->error);
    }

    public function testOverlappingQuotesAreProcessedOnce(): void
    {
        $verse = $this->canonical();
        $result = (new LlmIntegration(QuranValidator::fromDefaultDataset()))
            ->process('<quran ref="1:1">'.$verse.' (1:1)</quran>');

        self::assertCount(1, $result->quotes());
        self::assertSame('xml', $result->quotes()[0]->format);
    }

    public function testInvalidUtf8IsRejected(): void
    {
        $this->expectException(InvalidUtf8::class);

        (new LlmIntegration(QuranValidator::fromDefaultDataset()))->process("\xB1\x31");
    }

    public function testAutoCorrectsNormalizedQuote(): void
    {
        $processor = new LlmIntegration(QuranValidator::fromDefaultDataset(), new LlmIntegrationOptions(autoCorrect: true));
        $result = $processor->process('<quran ref="112:1">قل هو الله أحد</quran>');

        self::assertCount(1, $result->quotes());
        self::assertTrue($result->quotes()[0]->isValid());
        self::assertTrue($result->quotes()[0]->wasCorrected());
        self::assertNotSame($result->quotes()[0]->text, $result->quotes()[0]->correctedText);
    }

    public function testRangeQuote(): void
    {
        $validator = QuranValidator::fromDefaultDataset();
        $text = implode(' ', array_map(static fn ($verse): string => $verse->text, $validator->range('112:1-4')));
        $result = (new LlmIntegration($validator))->process('<quran ref="112:1-4">'.$text.'</quran>');
        self::assertTrue($result->quotes()[0]->isValid());
        self::assertSame('112:1-4', $result->quotes()[0]->reference);
    }

    private function canonical(): string
    {
        return QuranValidator::fromDefaultDataset()->verse('1:1')->text;
    }

    public function testSystemPromptsAvailable(): void
    {
        self::assertArrayHasKey('xml', LlmIntegration::SYSTEM_PROMPTS);
        self::assertArrayHasKey('markdown', LlmIntegration::SYSTEM_PROMPTS);
        self::assertArrayHasKey('bracket', LlmIntegration::SYSTEM_PROMPTS);
        self::assertArrayHasKey('minimal', LlmIntegration::SYSTEM_PROMPTS);
    }

    public function testContextualQuoteIsDetectedWithoutUntaggedScanning(): void
    {
        $processor = LlmIntegration::create(new LlmIntegrationOptions(scanUntagged: false));
        $result = $processor->process('Allah says: '.$this->canonical());

        self::assertCount(1, $result->quotes());
        self::assertSame('contextual', $result->quotes()[0]->detectionMethod);
        self::assertTrue($result->quotes()[0]->isValid());
    }

    public function testGetSystemPrompt(): void
    {
        $processor = new LlmIntegration(QuranValidator::fromDefaultDataset());

        self::assertStringContainsString('quran', strtolower($processor->getSystemPrompt()));
        self::assertSame(LlmIntegration::SYSTEM_PROMPTS['bracket'], $processor->getSystemPrompt('bracket'));
    }

    public function testGetSystemPromptRejectsUnknownFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LlmIntegration(QuranValidator::fromDefaultDataset()))->getSystemPrompt('unknown');
    }
    public function testInlineReference(): void
    {
        $result = (new LlmIntegration(QuranValidator::fromDefaultDataset()))->process(
            'بِسْمِ ٱللَّهِ ٱلرَّحْمَـٰنِ ٱلرَّحِيمِ (1:1)',
        );

        self::assertCount(1, $result->quotes());
        self::assertTrue($result->quotes()[0]->isValid());
        self::assertSame('1:1', $result->quotes()[0]->reference);
        self::assertSame('inline', $result->quotes()[0]->format);
        self::assertSame('tagged', $result->quotes()[0]->detectionMethod);
    }

    public function testQuickValidateFindsQuranContent(): void
    {
        $result = LlmIntegration::quickValidate(
            '<quran ref="1:1">'.$this->canonical().'</quran>',
        );

        self::assertTrue($result['has_quran_content']);
        self::assertTrue($result['all_valid']);
        self::assertSame([], $result['issues']);
    }

    public function testQuickValidateFindsNoQuranContent(): void
    {
        $result = LlmIntegration::quickValidate('This is just regular English text.');

        self::assertFalse($result['has_quran_content']);
        self::assertTrue($result['all_valid']);
        self::assertSame([], $result['issues']);
    }

    public function testQuickValidateReportsInvalidQuote(): void
    {
        $result = LlmIntegration::quickValidate('<quran ref="1:1">بسم الله الكريم</quran>');

        self::assertTrue($result['has_quran_content']);
        self::assertFalse($result['all_valid']);
        self::assertNotEmpty($result['issues']);
    }

    public function testQuickValidateReportsNormalizedQuoteAsImprecise(): void
    {
        $result = LlmIntegration::quickValidate('<quran ref="112:1">قل هو الله أحد</quran>');

        self::assertTrue($result['has_quran_content']);
        self::assertFalse($result['all_valid']);
        self::assertStringContainsString('imprecise', $result['issues'][0]);
    }
}
