# Quran Validator for PHP

Framework-independent Quran quotation validation for PHP 8.1+.

This project is a PHP port of the original [quran-validator](https://github.com/yazinsai/quran-validator).

## Installation

```bash
composer require watheqalshowaiter/quran-validator
```

## Quick start

```php
use Watheq\QuranValidator\QuranValidator;
use Watheq\QuranValidator\QuoteProcessor;

$validator = QuranValidator::fromDefaultDataset();
$result = $validator->validate('بسم الله الرحمن الرحيم');

$result->isValid();       // true
$result->reference();     // "1:1"
$result->matchType();     // "normalized"
$result->matchedVerse();  // QuranVerse

$reference = $validator->validateReference(
    text: $validator->verse('2:255')->text,
    reference: '2:255',
);

$verse = $validator->verse('2:255');
$range = $validator->range('112:1-4');
$results = $validator->search('الحي القيوم');

$processed = (new QuoteProcessor($validator))->process(
    '<quran ref="112:1">قل هو الله أحد</quran>',
);
```

`QuoteProcessor` recognizes XML, Markdown fences, `[[Q:reference|text]]` brackets, and `Arabic text (reference)` inline citations in the same input. It reports invalid quotes and, when a valid intended reference exists, replaces their text with the canonical bundled text.

## Normalization

`ArabicNormalizer` removes Arabic diacritics, tatweel, Quranic annotation marks, Arabic verse digits, punctuation, bidi/zero-width controls, and excess whitespace. It normalizes alef wasla and selected alef, Persian yeh, and Persian kaf forms. Matching additionally uses QUL's Imlaei simple text and known Uthmani/Imlaei orthographic equivalents.

Normalization is for comparison, not transliteration or scholarly textual transformation. Whole-verse validation remains exact or normalized only: partial and fuzzy matches are not accepted. Invalid UTF-8 throws `InvalidUtf8`.

## API

- `validate(string): ValidationResult`
- `validateReference(string, string): ValidationResult`
- `detectAndValidate(string): DetectionResult`
- `verse(string): QuranVerse`
- `range(string): list<QuranVerse>`
- `search(string, int = 10): list<SearchResult>`
- `analyzeFabrication(string): FabricationAnalysis`
- `QuoteProcessor::process(string): ProcessingResult`
- `QuoteProcessor::getSystemPrompt(string = "xml"): string`
- `QuoteProcessor::quickValidate(string): array`

Expected invalid quotations return result objects. Malformed or missing references/ranges and invalid datasets throw focused exceptions.

## Development

```bash
composer install # install dependencies
composer check # check correction of code 
composer test:coverage # run tests with code coverage
composer validate --strict # validate composer.json file 
```

## License and data

Original PHP source is MIT licensed. The Quran dataset has separate provenance and terms; see [DATASET-LICENSE.md](DATASET-LICENSE.md). Do not assume the source-code license covers the dataset.
