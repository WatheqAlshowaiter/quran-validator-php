<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watheq\QuranValidator\Exceptions\InvalidUtf8;
use Watheq\QuranValidator\LlmIntegration;
use Watheq\QuranValidator\QuranValidator;
use Watheq\QuranValidator\ValueObjects\DetectedQuote;
use Watheq\QuranValidator\ValueObjects\LlmIntegrationOptions;
use Watheq\QuranValidator\ValueObjects\MatchType;
use Watheq\QuranValidator\ValueObjects\NormalizeOptions;
use Watheq\QuranValidator\ValueObjects\ProcessingResult;
use Watheq\QuranValidator\ValueObjects\SearchResult;
use Watheq\QuranValidator\ValueObjects\ValidationResult;

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
        self::assertFalse($result->quotes()[0]->wasCorrected());
        self::assertSame($content, $result->correctedText());
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

    public function testWrongReferenceUsesActualVerse(): void
    {
        $content = '<quran ref="1:1">قُلْ هُوَ ٱللَّهُ أَحَدٌ</quran>';
        $result = (new LlmIntegration(QuranValidator::fromDefaultDataset()))->process($content);

        self::assertTrue($result->quotes()[0]->isValid());
        self::assertSame('112:1', $result->quotes()[0]->reference);
        self::assertTrue($result->quotes()[0]->wasCorrected());
    }

    public function testUntaggedQuotesProduceWarnings(): void
    {
        $result = (new LlmIntegration(QuranValidator::fromDefaultDataset()))->process(
            'Some text: قُلْ هُوَ ٱللَّهُ أَحَدٌ',
        );

        self::assertNotEmpty($result->warnings());
        self::assertStringContainsString('Untagged Quran quote detected', $result->warnings()[0]);
    }

    public function testDetectedQuoteAccessors(): void
    {
        $plain = new DetectedQuote('text', '1:1', 'xml', 0, 4, 0, 4);

        self::assertSame('text', $plain->original());
        self::assertSame('text', $plain->corrected());
        self::assertNull($plain->normalizedInput());
        self::assertNull($plain->expectedNormalized());
        self::assertFalse($plain->isValid());
        self::assertFalse($plain->wasCorrected());

        $normalized = new DetectedQuote(
            'text',
            '1:1',
            'xml',
            0,
            4,
            0,
            4,
            new ValidationResult(
                valid: true,
                matchType: MatchType::NORMALIZED,
                normalizedInput: 'normalized',
                expectedNormalized: 'expected',
            ),
            'fixed',
        );

        self::assertSame('fixed', $normalized->corrected());
        self::assertSame('normalized', $normalized->normalizedInput());
        self::assertSame('expected', $normalized->expectedNormalized());
        self::assertTrue($normalized->isValid());
        self::assertTrue($normalized->wasCorrected());
    }

    public function testValueObjectOptionsAndValidationTypes(): void
    {
        $options = new LlmIntegrationOptions(autoCorrect: false, scanUntagged: false, tagFormat: 'markdown');
        self::assertFalse($options->autoCorrect);
        self::assertFalse($options->scanUntagged);
        self::assertSame('markdown', $options->tagFormat);

        $normalization = new NormalizeOptions(stripHamza: true);
        self::assertTrue($normalization->diacritics);
        self::assertTrue($normalization->stripHamza);

        $result = new ValidationResult(valid: true, matchType: MatchType::EXACT, reference: '1:1');
        self::assertTrue($result->isValid());
        self::assertSame('exact', $result->matchType());
        self::assertSame(MatchType::EXACT, $result->matchTypeEnum());
        self::assertSame('1:1', $result->reference());
    }

    public function testRejectsUnsupportedLlmTagFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LlmIntegrationOptions(tagFormat: 'plain');
    }

    public function testProcessingResultAccessorsAndStatus(): void
    {
        $validQuote = new DetectedQuote(
            'text',
            '1:1',
            'xml',
            0,
            4,
            0,
            4,
            new ValidationResult(valid: true, matchType: MatchType::EXACT),
        );
        $result = new ProcessingResult('original', 'corrected', [$validQuote], ['warning']);

        self::assertSame('original', $result->originalText());
        self::assertSame('corrected', $result->correctedText());
        self::assertSame([$validQuote], $result->quotes());
        self::assertTrue($result->allValid());
        self::assertSame(['warning'], $result->warnings());
        self::assertFalse($result->hasErrors());

        $invalidQuote = new DetectedQuote('text', '1:1', 'xml', 0, 4, 0, 4, new ValidationResult(valid: false, matchType: MatchType::NONE));
        $invalidResult = new ProcessingResult('text', 'text', [$invalidQuote]);
        self::assertFalse($invalidResult->allValid());
        self::assertTrue($invalidResult->hasErrors());
    }

    public function testValidateQuoteHelper(): void
    {
        $processor = new LlmIntegration(QuranValidator::fromDefaultDataset());
        $valid = $processor->validateQuote('قُلْ هُوَ ٱللَّهُ أَحَدٌ', '112:1');
        self::assertTrue($valid['is_valid']);
        self::assertSame('112:1', $valid['actual_ref'] ?? null);

        $mismatch = $processor->validateQuote('قُلْ هُوَ ٱللَّهُ أَحَدٌ', '1:1');
        self::assertFalse($mismatch['is_valid']);
        self::assertSame('112:1', $mismatch['actual_ref'] ?? null);

        self::assertFalse($processor->validateQuote('not Quranic text')['is_valid']);

        $normalized = $processor->validateQuote('قل هو الله أحد', '112:1');
        self::assertTrue($normalized['is_valid']);
        self::assertSame(
            QuranValidator::fromDefaultDataset()->verse('112:1')->text,
            $normalized['correct_text'] ?? null,
        );
    }

    public function testSearchResultValueObject(): void
    {
        $verse = QuranValidator::fromDefaultDataset()->verse('1:1');
        $result = new SearchResult($verse, 0.85);

        self::assertSame($verse, $result->verse);
        self::assertSame(0.85, $result->score);
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

    public function testInvalidContextualTextIsIgnored(): void
    {
        $processor = LlmIntegration::create(new LlmIntegrationOptions(scanUntagged: false));
        $result = $processor->process('Allah says: بسم الله الكريم');

        self::assertSame([], $result->quotes());
    }

    public function testShortContextualTextIsIgnored(): void
    {
        $processor = LlmIntegration::create(new LlmIntegrationOptions(scanUntagged: false));

        self::assertSame([], $processor->process('Allah says: الله')->quotes());
    }

    public function testNormalizedContextualQuoteIsCorrected(): void
    {
        $processor = LlmIntegration::create(new LlmIntegrationOptions(
            autoCorrect: true,
            scanUntagged: false,
        ));
        $quote = $processor->process('Allah says: قل هو الله أحد')->quotes()[0];

        self::assertTrue($quote->isValid());
        self::assertSame(QuranValidator::fromDefaultDataset()->verse('112:1')->text, $quote->correctedText);
    }

    public function testExactContextualQuoteHasNoCorrection(): void
    {
        $processor = LlmIntegration::create(new LlmIntegrationOptions(
            autoCorrect: true,
            scanUntagged: false,
        ));
        $quote = $processor->process('Allah says: '.$this->canonical())->quotes()[0];

        self::assertTrue($quote->isValid());
        self::assertNull($quote->correctedText);
    }

    public function testExactUntaggedQuoteHasNoCorrection(): void
    {
        $processor = LlmIntegration::create(new LlmIntegrationOptions(autoCorrect: true));
        $quote = $processor->process($this->canonical())->quotes()[0];

        self::assertTrue($quote->isValid());
        self::assertNull($quote->correctedText);
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
