<?php

declare(strict_types=1);

namespace Watheq\QuranValidator;

use Watheq\QuranValidator\Contracts\QuoteParserInterface;
use Watheq\QuranValidator\Exceptions\InvalidQuranReference;
use Watheq\QuranValidator\Exceptions\InvalidUtf8;
use Watheq\QuranValidator\Parsing\BracketQuoteParser;
use Watheq\QuranValidator\Parsing\MarkdownQuoteParser;
use Watheq\QuranValidator\Parsing\XmlQuoteParser;
use Watheq\QuranValidator\ValueObjects\DetectedQuote;
use Watheq\QuranValidator\ValueObjects\ProcessingResult;
use Watheq\QuranValidator\ValueObjects\QuranVerse;
use Watheq\QuranValidator\ValueObjects\ValidationResult;

final class QuoteProcessor
{
    /** @var list<QuoteParserInterface> */
    private array $parsers;

    /** @param list<QuoteParserInterface>|null $parsers */
    public function __construct(
        private readonly QuranValidator $validator,
        ?array $parsers = null,
        private readonly bool $autoCorrect = true,
    ) {
        $this->parsers = $parsers ?? [new XmlQuoteParser(), new MarkdownQuoteParser(), new BracketQuoteParser()];
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
}
