<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\Parsing;

use Watheq\QuranValidator\Contracts\QuoteParserInterface;
use Watheq\QuranValidator\ValueObjects\DetectedQuote;

final class BracketQuoteParser implements QuoteParserInterface
{
    public function parse(string $content): array
    {
        preg_match_all('/\[\[Q:(\d{1,3}:\d{1,3}(?:-\d{1,3})?)\|([\s\S]*?)\]\]/iu', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $quotes = [];
        foreach ($matches as $match) {
            $quotes[] = new DetectedQuote(
                trim($match[2][0]),
                $match[1][0],
                'bracket',
                $match[0][1],
                $match[0][1] + strlen($match[0][0]),
                $match[2][1],
                $match[2][1] + strlen($match[2][0]),
            );
        }

        return $quotes;
    }
}
