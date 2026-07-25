# Repository Guidelines

## Project Structure

- `src/` contains the PHP package, including `QuranValidator`, Arabic normalization, quote parsing, dataset loading, exceptions, and value objects.
- `data/` contains the bundled Quran verse and surah JSON datasets.
- `tests/` contains PHPUnit tests grouped by feature, such as normalization, validation, dataset loading, and quote processing.
- `README.md` documents usage, development commands, and the project’s relationship to the original Quran Validator ports.

## Build, Test, and Development Commands

Install dependencies with `composer install`. For fast verification, run `vendor/bin/phpunit`, `vendor/bin/phpstan analyse`, and `vendor/bin/php-cs-fixer fix --dry-run --diff`. Validate package metadata with `composer validate --strict`. Coverage is intentionally skipped during routine checks; run `composer test:coverage` separately when coverage needs review.

## Coding Style & Naming

Target PHP 8.1+. Use strict types, four-space indentation, typed properties and parameters, and immutable value objects where appropriate. Classes and methods use `PascalCase` and `camelCase`; PHPUnit tests use descriptive `test...` method names. Apply the repository PHP-CS-Fixer configuration before submitting changes. Keep Arabic normalization rules documented near their regex constants.

## Testing Guidelines

PHPUnit is the test framework. Add regression tests for behavior changes, especially normalization, Quran references, matching variants, and parser formats. Keep tests in the matching feature file and run the complete suite before opening a pull request. Coverage is not part of the fast test loop; use `composer test:coverage` separately when needed.

## Commit & Pull Requests

Use short imperative Conventional Commit-style subjects, such as `fix: handle spaced Quran variants` or `test: cover Arabic normalization`. Pull requests should explain the behavior changed, identify relevant tests, and include documentation updates when public APIs or commands change. Do not commit generated dependency or coverage artifacts.

## Compatibility & Data

The package supports PHP 8.1 and newer. Keep runtime dependencies minimal and preserve compatibility with the bundled JSON datasets. Changes to normalization or matching should be tested against both Uthmani and simplified Arabic text.
