<?php

declare(strict_types=1);

namespace Watheq\QuranValidator;

use InvalidArgumentException;
use Watheq\QuranValidator\Contracts\QuoteParserInterface;
use Watheq\QuranValidator\Exceptions\InvalidQuranReference;
use Watheq\QuranValidator\Exceptions\InvalidUtf8;
use Watheq\QuranValidator\Exceptions\InvalidVerseRange;
use Watheq\QuranValidator\Parsing\BracketQuoteParser;
use Watheq\QuranValidator\Parsing\InlineReferenceParser;
use Watheq\QuranValidator\Parsing\MarkdownQuoteParser;
use Watheq\QuranValidator\Parsing\XmlQuoteParser;
use Watheq\QuranValidator\ValueObjects\DetectedQuote;
use Watheq\QuranValidator\ValueObjects\LlmIntegrationOptions;
use Watheq\QuranValidator\ValueObjects\ProcessingResult;
use Watheq\QuranValidator\ValueObjects\QuranReference;
use Watheq\QuranValidator\ValueObjects\QuranVerse;
use Watheq\QuranValidator\ValueObjects\ValidationResult;

final class LlmIntegration
{
    private const QURAN_CONTEXT_PATTERNS = [
        "~(?:Allah\s+says?|God\s+says?|the\s+Quran\s+says?|in\s+the\s+Quran|Quranic\s+verse|verse\s+states?|ayah|ayat|surah)\s*[:\-]?\s*~iu",
        "~(?:قال\s+الله|قال\s+تعالى|يقول\s+الله|في\s+القرآن|الآية|سورة)\s*[:\-]?\s*~u",
        "~\(?\d{1,3}:\d{1,3}(?:-\d{1,3})?\)?~u",
        "~\[[\w\-]+:\d+(?:-\d+)?\]~u",
    ];

    private const ARABIC_AFTER_CONTEXT = "~^[\p{Arabic}\s]+~u";
    /** Create an integration using the default validator and options. */
    public static function create(?LlmIntegrationOptions $options = null): self
    {
        return new self(QuranValidator::fromDefaultDataset(), $options);
    }

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
    private readonly LlmIntegrationOptions $options;

    /** @param  list<QuoteParserInterface>|null  $parsers */
    public function __construct(
        private readonly QuranValidator $validator,
        ?LlmIntegrationOptions $options = null,
        ?array $parsers = null,
    ) {
        $this->options = $options ?? new LlmIntegrationOptions();
        $this->parsers = $parsers ?? [
            new XmlQuoteParser(),
            new MarkdownQuoteParser(),
            new BracketQuoteParser(),
            new InlineReferenceParser(),
        ];
    }

    /**
     * @param list<DetectedQuote> $alreadyFound
     *
     * @return list<array{text: string, start: int, end: int}>
     */
    private function _extractContextualQuotes(string $text, array $alreadyFound): array
    {
        $results = [];
        foreach (self::QURAN_CONTEXT_PATTERNS as $pattern) {
            preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$context, $offset]) {
                $start = (int) $offset + strlen($context);
                $after = substr($text, $start);
                if (preg_match(self::ARABIC_AFTER_CONTEXT, $after, $arabic) !== 1) {
                    continue;
                }
                $segment = trim($arabic[0]);
                if (mb_strlen($segment) < 10) {
                    continue;
                }
                $end = $start + strlen($arabic[0]);
                $overlaps = array_filter($alreadyFound, static fn (DetectedQuote $quote): bool =>
                    ($start >= $quote->start && $start < $quote->end)
                    || ($end > $quote->start && $end <= $quote->end));
                if ($overlaps === []) {
                    $results[] = ['text' => $segment, 'start' => $start, 'end' => $end];
                }
            }
        }
        return $results;
    }

    /**
     * Process LLM output to validate and optionally correct Quran quotations.
     *
     * @param  string  $content  LLM-generated text.
     *
     * @return ProcessingResult Processed output with validation results.
     */
    public function process(string $content): ProcessingResult
    {
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new InvalidUtf8('Input must be valid UTF-8.');
        }

        // extract and validate tagged quotes first.
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

            $wasCorrected = false;
            try {
                $validation = $this->validator->validateAgainst($quote->text, $quote->reference);
                $canonical = implode(' ', array_map(
                    static fn (QuranVerse $verse): string => $verse->text,
                    $this->validator->range($quote->reference),
                ));

                $parsedReference = QuranReference::parse($quote->reference);
                if (!$validation->isValid() && !$parsedReference->isRange()) {
                    $global = $this->validator->validate($quote->text);
                    if ($global->isValid() && $global->matchedVerse() !== null) {
                        $validation = $global;
                        $canonical = $global->matchedVerse()->text;
                        $wasCorrected = true;
                    }
                }

                if ($validation->isValid() && $validation->matchType() !== 'exact') {
                    $wasCorrected = true;
                }
                $correction = $this->options->autoCorrect && $wasCorrected ? $canonical : null;
            } catch (InvalidQuranReference|InvalidVerseRange $exception) {
                $validation = new ValidationResult(
                    valid: false,
                    matchType: 'none',
                    reference: $quote->reference,
                    error: $exception->getMessage()
                );
                $correction = null;
            }

            $effectiveReference = $validation->reference() ?? $quote->reference;
            $quotes[] = new DetectedQuote(
                text: $quote->text,
                reference: $effectiveReference,
                format: $quote->format,
                start: $quote->start,
                end: $quote->end,
                textStart: $quote->textStart,
                textEnd: $quote->textEnd,
                validation: $validation,
                correctedText: $correction,
                wasCorrected: $wasCorrected,
            );
        }

        foreach ($this->_extractContextualQuotes($content, $quotes) as $context) {
            $validation = $this->validator->validate($context['text']);
            if (!$validation->isValid()) {
                continue;
            }
            $matchedVerse = $validation->matchedVerse();
            $wasCorrected = $validation->matchType() !== 'exact';
            $correction = $this->options->autoCorrect
                && $validation->matchType() !== 'exact'
                && $matchedVerse !== null
                ? $matchedVerse->text
                : null;
            $quotes[] = new DetectedQuote(
                text: $context['text'],
                reference: $validation->reference() ?? '',
                format: 'plain',
                start: $context['start'],
                end: $context['end'],
                textStart: $context['start'],
                textEnd: $context['end'],
                validation: $validation,
                correctedText: $correction,
                detectionMethod: 'contextual',
                wasCorrected: $wasCorrected,
            );
        }

        // Scan untagged Arabic segments and skip overlaps with tagged quotes.
        if ($this->options->scanUntagged) {
            foreach ($this->validator->detectAndValidate($content)->segments as $segment) {
                if (mb_strlen($segment->text) < 15) {
                    continue;
                }
                $overlaps = array_filter($quotes, static fn (
                    DetectedQuote $quote
                ): bool => ($segment->start >= $quote->start && $segment->start < $quote->end)
                    || ($segment->end > $quote->start && $segment->end <= $quote->end));
                if (!$segment->validation->isValid() || $overlaps !== []) {
                    continue;
                }

                $matchedVerse = $segment->validation->matchedVerse();
                $wasCorrected = $segment->validation->matchType() !== 'exact';
                $correction = $this->options->autoCorrect
                && $segment->validation->matchType() !== 'exact'
                && $matchedVerse !== null
                    ? $matchedVerse->text
                    : null;
                $quotes[] = new DetectedQuote(
                    text: $segment->text,
                    reference: $segment->validation->reference() ?? '',
                    format: 'plain',
                    start: $segment->start,
                    end: $segment->end,
                    textStart: $segment->start,
                    textEnd: $segment->end,
                    validation: $segment->validation,
                    correctedText: $correction,
                    detectionMethod: 'fuzzy',
                    wasCorrected: $wasCorrected,
                );
            }
            usort($quotes, static fn (DetectedQuote $a, DetectedQuote $b): int => $a->start <=> $b->start);
        }

        // Apply corrections after all quote analyses are complete.
        $corrected = $content;
        foreach (array_reverse($quotes) as $quote) {
            if ($quote->correctedText !== null) {
                $corrected = substr_replace(
                    $corrected,
                    $quote->correctedText,
                    $quote->textStart,
                    $quote->textEnd - $quote->textStart
                );
            }
        }

        $warnings = [];
        foreach ($quotes as $quote) {
            if ($quote->detectionMethod === 'fuzzy') {
                $warnings[] = sprintf(
                    'Untagged Quran quote detected: "%s..." (%s)',
                    mb_substr($quote->text, 0, 50),
                    $quote->reference,
                );
            }
        }

        return new ProcessingResult($content, $corrected, $quotes, $warnings);
    }

    /**
     * Validate one quote without processing a complete response.
     *
     * @return array{is_valid: bool, correct_text?: ?string, actual_ref?: ?string}
     */
    public function validateQuote(string $text, ?string $expectedReference = null): array
    {
        $validation = $this->validator->validate($text);
        if (!$validation->isValid()) {
            return ['is_valid' => false];
        }

        if ($expectedReference !== null && $validation->reference() !== $expectedReference) {
            return [
                'is_valid' => false,
                'correct_text' => $validation->matchedVerse()?->text,
                'actual_ref' => $validation->reference(),
            ];
        }

        $needsCorrection = $validation->matchType() !== 'exact';
        return [
            'is_valid' => true,
            'correct_text' => $needsCorrection ? $validation->matchedVerse()?->text : null,
            'actual_ref' => $validation->reference(),
        ];
    }

    /** Return the system prompt for a supported quote format. */
    public function getSystemPrompt(?string $format = null): string
    {
        $format ??= $this->options->tagFormat;
        return self::SYSTEM_PROMPTS[$format]
            ?? throw new InvalidArgumentException(sprintf('Unsupported tag format: %s.', $format));
    }

    /**
     * Quickly validate a complete LLM response.
     *
     * @param string $content LLM output to validate.
     *
     * @return array{has_quran_content: bool, all_valid: bool, issues: list<string>}
     */
    public static function quickValidate(string $content): array
    {
        $result = (new self(
            QuranValidator::fromDefaultDataset(),
            new LlmIntegrationOptions(autoCorrect: false)
        ))->process($content);
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
