<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\Contracts;

use Watheq\QuranValidator\ValueObjects\DetectedQuote;

interface QuoteParserInterface
{
    /** @return list<DetectedQuote> */
    public function parse(string $content): array;
}
