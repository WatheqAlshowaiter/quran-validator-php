<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\ValueObjects;

enum MatchType: string
{
    case EXACT = 'exact';
    case NORMALIZED = 'normalized';
    case NONE = 'none';
}
