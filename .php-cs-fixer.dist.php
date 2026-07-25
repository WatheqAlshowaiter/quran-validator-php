<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP81Migration' => true,
        'declare_strict_types' => true,
        'no_extra_blank_lines' => true,
        'ordered_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
    ])
    ->setFinder((new Finder())->in([__DIR__.'/src', __DIR__.'/tests']));
