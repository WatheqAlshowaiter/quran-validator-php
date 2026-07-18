<?php

declare(strict_types=1);

namespace Watheq\QuranValidator;

use InvalidArgumentException;
use Watheq\QuranValidator\Contracts\QuoteParserInterface;
use Watheq\QuranValidator\Exceptions\InvalidQuranReference;
use Watheq\QuranValidator\Exceptions\InvalidUtf8;
use Watheq\QuranValidator\Parsing\BracketQuoteParser;
use Watheq\QuranValidator\Parsing\InlineReferenceParser;
use Watheq\QuranValidator\Parsing\MarkdownQuoteParser;
use Watheq\QuranValidator\Parsing\XmlQuoteParser;
use Watheq\QuranValidator\ValueObjects\DetectedQuote;
use Watheq\QuranValidator\ValueObjects\ProcessingResult;
use Watheq\QuranValidator\ValueObjects\QuranVerse;
use Watheq\QuranValidator\ValueObjects\ValidationResult;

final class QuoteProcessor
{
    /**
     * @var mixed[]
     */
    public const SYSTEM_PROMPTS = [
        'xml' => "When quoting verses from the Quran, you MUST use this exact format:\n"
            ."<quran ref=\"SURAH:AYAH\">ARABIC_TEXT</quran>\n\n"
            ."For multiple consecutive verses, use a range:\n"
            ."<quran ref=\"SURAH:START-END\">ARABIC_TEXT</quran>\n\n"
            ."Examples:\n"
            ."<quran ref=\"1:1\">بِسْمِ ٱللَّهِ ٱلرَّحْمَٰنِ ٱلرَّحِيمِ</quran>\n"
            ."<quran ref=\"112:1-4\">قُلْ هُوَ ٱللَّهُ أَحَدٌ ٱللَّهُ ٱلصَّمَدُ لَمْ يَلِدْ وَلَمْ يُولَدْ وَلَمْ يَكُن لَّهُۥ كُفُوًا أَحَدٌۢ</quran>\n\n"
            ."Rules:\n"
            ."- Always include the reference (surah:ayah or surah:start-end for ranges)\n"
            ."- Use the exact Arabic text with full diacritics if possible\n"
            ."- Never paraphrase or partially quote without indication\n"
            .'- If unsure of exact wording, say "approximately" before the quote',
        'markdown' => "When quoting verses from the Quran, use this format:\n"
            ."\x60\x60\x60quran ref=\"SURAH:AYAH\"\nARABIC_TEXT\n\x60\x60\x60\n\n"
            ."For verse ranges, use:\n"
            ."\x60\x60\x60quran ref=\"SURAH:START-END\"\nARABIC_TEXT\n\x60\x60\x60\n\n"
            ."Example:\n"
            ."\x60\x60\x60quran ref=\"112:1-4\"\n"
            ."قُلْ هُوَ ٱللَّهُ أَحَدٌ ٱللَّهُ ٱلصَّمَدُ لَمْ يَلِدْ وَلَمْ يُولَدْ وَلَمْ يَكُن لَّهُۥ كُفُوًا أَحَدٌۢ\n"
            ."\x60\x60\x60",
        'bracket' => "When quoting Quran verses, use: [[Q:SURAH:AYAH|ARABIC_TEXT]]\n"
            ."For verse ranges: [[Q:SURAH:START-END|ARABIC_TEXT]]\n\n"
            ."Example: [[Q:1:1|بِسْمِ ٱللَّهِ ٱلرَّحْمَٰنِ ٱلرَّحِيمِ]]\n"
            .'Example range: [[Q:112:1-4|قُلْ هُوَ ٱللَّهُ أَحَدٌ ٱللَّهُ ٱلصَّمَدُ لَمْ يَلِدْ وَلَمْ يُولَدْ وَلَمْ يَكُن لَّهُۥ كُفُوًا أَحَدٌۢ]]',
        'minimal' => 'Always cite Quran verses with their reference number in parentheses '
            .'immediately after, like: "بِسْمِ ٱللَّهِ ٱلرَّحْمَٰنِ ٱلرَّحِيمِ (1:1)" '
            .'or for ranges "... (112:1-4)"',
    ];

    /** @var list<QuoteParserInterface> */
    private array $parsers;

    /** @param list<QuoteParserInterface>|null $parsers */
    public function __construct(
        private readonly QuranValidator $validator,
        ?array $parsers = null,
        private readonly bool $autoCorrect = true,
    ) {
        $this->parsers = $parsers ?? [
            new XmlQuoteParser(),
            new MarkdownQuoteParser(),
            new BracketQuoteParser(),
            new InlineReferenceParser(),
        ];
    }

    public function process(string $content): ProcessingResult
    {
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new InvalidUtf8('Input must be valid UTF-8.');
        }

        $parsed = [];
        foreach ($this->parsers as $parser) {
            array_push($parsed, ...$parser->parse($content));
        }
        usort($parsed, static fn (DetectedQuote $a, DetectedQuote $b): int => $a->start <=> $b->start);

        $quotes = [];
        $lastEnd = -1;
        foreach ($parsed as $quote) {
            if ($quote->start < $lastEnd) {
                continue;
            }
            $lastEnd = $quote->end;

            try {
                $validation = $this->validator->validateReference($quote->text, $quote->reference);
                $canonical = implode(' ', array_map(
                    static fn (QuranVerse $verse): string => $verse->text,
                    $this->validator->range($quote->reference),
                ));
                $correction = $this->autoCorrect && $canonical !== $quote->text ? $canonical : null;
            } catch (InvalidQuranReference $exception) {
                $validation = new ValidationResult(false, 'none', reference: $quote->reference, error: $exception->getMessage());
                $correction = null;
            }

            $quotes[] = new DetectedQuote(
                $quote->text,
                $quote->reference,
                $quote->format,
                $quote->start,
                $quote->end,
                $quote->textStart,
                $quote->textEnd,
                $validation,
                $correction,
            );
        }

        $corrected = $content;
        foreach (array_reverse($quotes) as $quote) {
            if ($quote->correctedText !== null) {
                $corrected = substr_replace($corrected, $quote->correctedText, $quote->textStart, $quote->textEnd - $quote->textStart);
            }
        }

        return new ProcessingResult($content, $corrected, $quotes);
    }

    public function getSystemPrompt(string $format = 'xml'): string
    {
        return self::SYSTEM_PROMPTS[$format]
            ?? throw new InvalidArgumentException(sprintf('Unsupported tag format: %s.', $format));
    }

    /** @return array{has_quran_content: bool, all_valid: bool, issues: list<string>} */
    public static function quickValidate(string $content): array
    {
        $result = (new self(QuranValidator::fromDefaultDataset(), autoCorrect: false))->process($content);
        $issues = [];

        foreach ($result->quotes() as $quote) {
            if ($quote->isValid() && $quote->validation?->matchType() === 'exact') {
                continue;
            }

            $status = $quote->isValid() ? 'imprecise' : 'invalid';
            $issues[] = sprintf(
                'Quote "%s..." is %s (should be %s)',
                mb_substr($quote->text, 0, 30),
                $status,
                $quote->reference,
            );
        }

        return [
            'has_quran_content' => $result->quotes() !== [],
            'all_valid' => $issues === [],
            'issues' => $issues,
        ];
    }
}
