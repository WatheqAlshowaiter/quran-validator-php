# Contributing

Use PHP 8.3+, run `composer install`, and add focused regression tests for behavior changes. Before submitting changes, run `composer validate --strict`, `vendor/bin/phpunit`, `vendor/bin/phpstan analyse`, and `vendor/bin/php-cs-fixer fix --dry-run --diff`.

Dataset corrections must identify an authoritative upstream source and preserve `DATASET-LICENSE.md` attribution.
