<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

use InvalidArgumentException;

final class LlmIntegrationOptions
{
    public function __construct(
        public readonly bool $autoCorrect = true,
        public readonly bool $scanUntagged = true,
        public readonly string $tagFormat = 'xml',
    ) {
        if (!in_array($this->tagFormat, ['xml', 'markdown', 'bracket'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported tag format: %s.', $this->tagFormat));
        }
    }
}
