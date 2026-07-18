<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\Parsing;

use Watheq\QuranValidator\Contracts\QuoteParserInterface;
use Watheq\QuranValidator\ValueObjects\DetectedQuote;

final class XmlQuoteParser implements QuoteParserInterface
{
    public function parse(string $content): array
    {
        preg_match_all('/<quran\s+ref=(["\'])(\d{1,3}:\d{1,3}(?:-\d{1,3})?)\1>([\s\S]*?)<\/quran>/iu', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $quotes = [];
        foreach ($matches as $match) {
            $start = (int) $match[0][1];
            $textStart = (int) $match[3][1];
            $quotes[] = new DetectedQuote(
                trim($match[3][0]),
                $match[2][0],
                'xml',
                $start,
                $start + strlen($match[0][0]),
                $textStart,
                $textStart + strlen($match[3][0]),
            );
        }

        return $quotes;
    }
}
