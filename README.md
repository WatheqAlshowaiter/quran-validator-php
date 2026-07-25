# Quran Validator for PHP

Validate and verify Quranic verses in LLM-generated text with high accuracy for PHP 8.1+.

This project is a PHP port of the original [quran-validator](https://github.com/yazinsai/quran-validator).

## The Problem

LLMs can misquote Quranic verses—subtly changing words, missing diacritics, or combining verses incorrectly. This library provides:

1. **System prompts** that instruct LLMs to tag Quran quotes in a parseable format
2. **Post-processing** that validates tagged quotes against the authentic Quran database
3. **Auto-correction** that fixes imprecise quotes using the authentic text
4. **Detection** of untagged Arabic text that might be Quran verses

## Features

- **LLM Integration**: System prompts and post-processing for complete LLM pipelines
- **Multi-tier Matching**: Exact and normalized matching with Uthmani script support
- **Auto-Correction**: Replace valid imprecise quotes with canonical text
- **Arabic Normalization**: Handle diacritics, alef variants, alef wasla, and more
- **Fabrication Detection**: Identify words that do not occur in the Quran
- **Full Quran Database**: All 6,236 verses bundled
- **Minimal Dependencies**: Runtime polyfills for Unicode normalization and multibyte strings
- **Typed API**: Strict types, typed value objects, and PHPStan analysis

## Installation

```bash
composer require watheqalshowaiter/quran-validator
```

## Quick Start: LLM Integration

### Step 1: Add the System Prompt

```php
use Watheq\QuranValidator\LlmIntegration;

$systemPrompt = LlmIntegration::SYSTEM_PROMPTS['xml']
    ."\n\n"
    .$yourOtherInstructions;

// The LLM should now output Quran quotes like:
// <quran ref="1:1">بِسْمِ ٱللَّهِ ٱلرَّحْمَٰنِ ٱلرَّحِيمِ</quran>
```

### Step 2: Process the LLM Response

```php
use Watheq\QuranValidator\LlmIntegration;

$processor = LlmIntegration::create();
$result = $processor->process($llmResponse);

if (!$result->allValid()) {
    $invalid = array_filter(
        $result->quotes(),
        static fn ($quote): bool => !$quote->isValid(),
    );
}

echo $result->correctedText();

foreach ($result->quotes() as $quote) {
    $status = $quote->isValid() ? 'valid' : 'invalid';
    echo "{$quote->reference}: {$status} ({$quote->detectionMethod})\n";
}
```

### Step 3: Handle Warnings

```php
foreach ($result->warnings() as $warning) {
    echo $warning."\n";
    // Untagged Quran quote detected: "قُلْ هُوَ..." (112:1)
}
```

## Direct Validation API

```php
use Watheq\QuranValidator\QuranValidator;

$validator = QuranValidator::fromDefaultDataset();
$result = $validator->validate('بِسْمِ ٱللَّهِ ٱلرَّحْمَٰنِ ٱلرَّحِيمِ');

var_dump($result->isValid());   // true
echo $result->reference();      // 1:1
echo $result->matchType();      // exact, normalized, or none

if ($result->matchType() !== 'exact' && $result->matchedVerse() !== null) {
    echo $result->matchedVerse()->text;
}
```

## Validate Against a Specific Reference

```php
$result = $validator->validateAgainst('بسم الله', '1:1');

if (!$result->isValid()) {
    echo "Expected: {$result->expectedNormalized}\n";
    echo "Got: {$result->normalizedInput}\n";
    echo "Mismatch at index: {$result->mismatchIndex}\n";
}
```

## Verse Range Support

The processor accepts both single references and consecutive verse ranges:

```text
<quran ref="1:1">بِسْمِ ٱللَّهِ ٱلرَّحْمَٰنِ ٱلرَّحِيمِ</quran>
<quran ref="112:1-4">قُلْ هُوَ ٱللَّهُ أَحَدٌ ...</quran>
```

```php
$range = $validator->getVerseRange(112, 1, 4);

if ($range !== null) {
    echo $range['text'];
    foreach ($range['verses'] as $verse) {
        echo $verse->reference()."\n";
    }
}

// String references are also supported:
$verses = $validator->range('112:1-4');
```

## Fabrication Detection

Identify words that do not occur in the Quran:

```php
$analysis = $validator->analyzeFabrication('بسم الله الفلان');

foreach ($analysis->words as $word) {
    $status = $word->fabricated ? 'fabricated' : 'valid';
    echo "{$word->word}: {$status}\n";
}

echo $analysis->stats->fabricatedRatio;
```

## System Prompt Formats

```php
use Watheq\QuranValidator\LlmIntegration;

LlmIntegration::SYSTEM_PROMPTS['xml'];
// <quran ref="1:1">...</quran>

LlmIntegration::SYSTEM_PROMPTS['markdown'];
// A fenced quran code block with a reference

LlmIntegration::SYSTEM_PROMPTS['bracket'];
// [[Q:1:1|...]]

LlmIntegration::SYSTEM_PROMPTS['minimal'];
// ... (1:1)
```

## LLM Integration Options

```php
use Watheq\QuranValidator\LlmIntegration;
use Watheq\QuranValidator\ValueObjects\LlmIntegrationOptions;

$processor = LlmIntegration::create(new LlmIntegrationOptions(
    autoCorrect: true,
    scanUntagged: true,
    tagFormat: 'xml',
));
```

Supported tag formats are `xml`, `markdown`, and `bracket`.

## Detection Methods

`LlmIntegration` reports how each quotation was found:

| Method | Description | When used |
|--------|-------------|-----------|
| `tagged` | XML, Markdown, bracket, or inline reference | Checked first |
| `contextual` | Arabic after phrases such as “Allah says” or “in the Quran” | After tagged quotes |
| `fuzzy` | Untagged Arabic that validates as a complete Quran verse | When `scanUntagged` is enabled |

## Quick Validation

```php
use Watheq\QuranValidator\LlmIntegration;

$result = LlmIntegration::quickValidate($llmResponse);

var_dump($result['has_quran_content']);
var_dump($result['all_valid']);
print_r($result['issues']);
```

## Verse Lookup and Search

```php
$verse = $validator->getVerse(2, 255);
echo $verse?->text;

$surah = $validator->getSurah(1);
echo $surah?->englishName;  // Al-Fatiha
echo $surah?->versesCount;  // 7

$results = $validator->search('الرحمن الرحيم', limit: 5);
foreach ($results as $result) {
    $verse = $result['verse'];
    printf("%s (%.2f)\n", $verse->reference(), $result['similarity']);
}
```

## Arabic Text Utilities

```php
use Watheq\QuranValidator\ArabicNormalizer;

$normalizer = new ArabicNormalizer();

$normalizer->normalize('السَّلَامُ عَلَيْكُمُ'); // السلام عليكم
$normalizer->removeDiacritics('بِسْمِ اللَّهِ'); // بسم الله
$normalizer->containsArabic('Hello مرحبا world'); // true

$segments = $normalizer->extractArabicSegments('Say بسم الله and continue');
foreach ($segments as $segment) {
    echo "{$segment->text}: {$segment->start}-{$segment->end}\n";
}
```

## End-to-End Processing

The package is LLM-provider agnostic. Pass the generated response through the processor before displaying or storing it:

```php
use Watheq\QuranValidator\LlmIntegration;

function validateLlmResponse(string $response): string
{
    $result = LlmIntegration::create()->process($response);

    if ($result->hasErrors()) {
        throw new RuntimeException('The response contains an invalid Quran quotation.');
    }

    return $result->correctedText();
}
```

## Match Types

| Type | Description |
|------|-------------|
| `exact` | Perfect character-by-character match |
| `normalized` | Match after Arabic normalization |
| `none` | No match found |

## Development

```bash
composer install
composer test
composer types
composer format:check
composer test:coverage
composer validate --strict
```

## QuranValidator.com

[quranvalidator.com](https://quranvalidator.com) is a live service built by the original author of Quran Validator, using the JavaScript version. It evaluates how well multiple LLMs handle Quran quotations—including textual accuracy and reference correctness—and publishes the results in a transparent leaderboard.

## Data Source

Uses Quranic data from [QUL (Quranic Universal Library)](https://qul.tarteel.ai/) by [Tarteel AI](https://tarteel.ai/):

- **Uthmani Script**: Arabic text with full diacritics
- **Imlaei Simple**: Simplified Arabic used for matching

| | |
|---|---|
| **Total verses** | 6,236 |
| **Total surahs** | 114 |
| **Uthmani source** | QUL Uthmani, ayah by ayah |
| **Simple source** | QUL Imlaei Simple |
| **Encoding** | UTF-8 |

### Credits

- [Tarteel AI](https://tarteel.ai/) for creating and maintaining QUL
- [QUL](https://qul.tarteel.ai/) for its Quranic resources
- [Yazin Alirhayim](https://github.com/yazinsai) for the original Quran Validator

## Contributing

Contributions are welcome. Run the development commands above before submitting a pull request, and include tests for changes to normalization, matching, references, or quote parsing.

## License and Data

The PHP source is MIT licensed; see [LICENSE](LICENSE). The Quran dataset has separate provenance and terms; see [DATASET-LICENSE.md](DATASET-LICENSE.md). Do not assume the source-code license covers the dataset.
