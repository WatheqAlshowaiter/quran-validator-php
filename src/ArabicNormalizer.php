<?php

declare(strict_types=1);

namespace Watheq\QuranValidator;

use Normalizer;
use Watheq\QuranValidator\Contracts\ArabicNormalizerInterface;
use Watheq\QuranValidator\Exceptions\InvalidUtf8;
use Watheq\QuranValidator\ValueObjects\ArabicSegment;
use Watheq\QuranValidator\ValueObjects\NormalizeOptions;

/**
 * Arabic text normalization for Quran validation.
 *
 * Ports the normalization rules used by the original Quran Validator.
 */
final class ArabicNormalizer implements ArabicNormalizerInterface
{
    // Arabic tashkeel/harakat: U+064B-U+065F, including shadda U+0651.
    private const DIACRITICS = '/[\x{064B}-\x{065F}]/u';

    // Alif with madda above (آ U+0622) becomes plain alif (ا U+0627).
    private const ALIF_MADDA = '/\x{0622}/u';

    // Alif wasla (ٱ U+0671) becomes plain alif (ا U+0627).
    private const ALIF_WASLA = '/\x{0671}/u';

    // Alif variants U+0672 and U+0673 become plain alif.
    private const ALIF_VARIANTS = '/[\x{0672}\x{0673}]/u';

    // Superscript alif preceded by regular alif (اٰ) collapses to one alif.
    private const ALIF_SUPERSCRIPT_ALIF = '/\x{0627}\x{0670}/u';

    // Remaining superscript alif (ٰ U+0670) becomes plain alif.
    private const SUPERSCRIPT_ALIF = '/\x{0670}/u';

    // Farsi and Urdu yeh variants become Arabic yeh (ي U+064A).
    private const FARSI_YEH = '/[\x{06CC}\x{06D2}]/u';

    // Farsi and Urdu kaf becomes Arabic kaf (ك U+0643).
    private const FARSI_KAF = '/\x{06A9}/u';

    // Quranic annotation marks: U+06D6-U+06ED.# Farsi/Urdu yeh variants -> Arabic yeh (ي U+064A)

    private const QURANIC_ANNOTATIONS = '/[\x{06D6}-\x{06ED}]/u';

    // Tatweel/kashida: U+0640.
    private const TATWEEL = '/\x{0640}/u';

    // Ornate parentheses: U+FD3E and U+FD3F. ﴾﴿
    private const ORNATE_PARENS = '/[\x{FD3E}\x{FD3F}]/u';

    // Arabic-Indic and Extended Arabic-Indic digits.
    private const ARABIC_DIGITS = '/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u';

    // Western and Arabic punctuation removed during normalization.
    private const PUNCTUATION = '/[.,;:!?…،؛؟]/u';

    // Consecutive whitespace collapsed to one space.
    private const MULTI_WHITESPACE = '/\s+/u';

    # Hamza forms to strip (أ إ ئ ء) — ؤ is preserved
    private const HAMZA_TO_STRIP = '/[\x{0621}\x{0623}\x{0625}\x{0626}]/u';

    // Alef maqsura (ى U+0649) becomes Arabic yeh for matching.
    private const ALEF_MAQSURA = '/\x{0649}/u';

    // Uthmani spelling: وة or واة becomes اة.
    private const UTHMANI_WAW_TA = '/وا?ة/u';

    // Double yeh collapses to one yeh.
    private const DOUBLE_YA = '/يي/u';

    // Sad/sin orthographic variants accepted during matching.
    private const SAD_VARIANT = '/بصط/u';
    private const SIN_VARIANT = '/صيطر/u';

    // Collapse the definite article's doubled lam.
    private const DOUBLE_LAM = '/(ا)لل/u';

    // Bidirectional and zero-width control characters.
    private const BIDI_CONTROLS = '/[\x{200C}\x{200D}\x{200E}\x{200F}\x{061C}]/u';

    // Arabic character detection across the supported Unicode blocks.
    private const ARABIC_CHARACTER = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u';

    // Arabic segment extraction from mixed-language text.
    private const ARABIC_SEGMENT = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}][\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}\s]*/u';

    // Common spaced form of يا أيها handled only by Quran matching.
    private const STANDALONE_AYYUHA = '/(^| )يها(?= |$)/u';

    /**
     * Apply the configurable core normalization rules.
     *
     * @param string $text UTF-8 Arabic text to normalize.
     * @param NormalizeOptions $options Normalization options.
     *
     * @return string Normalized text.
     */
    private function normalizeCore(string $text, NormalizeOptions $options): string
    {
        if ($options->diacritics) {
            $text = preg_replace(self::DIACRITICS, '', $text) ?? $text;
            $text = preg_replace(self::ALIF_MADDA, 'ا', $text) ?? $text;
            $text = preg_replace(self::ALIF_WASLA, 'ا', $text) ?? $text;
            $text = preg_replace(self::ALIF_VARIANTS, 'ا', $text) ?? $text;
            $text = preg_replace(self::ALIF_SUPERSCRIPT_ALIF, 'ا', $text) ?? $text;
            $text = preg_replace(self::SUPERSCRIPT_ALIF, 'ا', $text) ?? $text;
            $text = preg_replace(self::FARSI_YEH, 'ي', $text) ?? $text;
            $text = preg_replace(self::FARSI_KAF, 'ك', $text) ?? $text;
        }

        if ($options->markers || $options->smallLetters) {
            $text = preg_replace(self::QURANIC_ANNOTATIONS, '', $text) ?? $text;
        }

        if ($options->verseNumbers) {
            $text = preg_replace(self::ORNATE_PARENS, '', $text) ?? $text;
            $text = preg_replace(self::ARABIC_DIGITS, '', $text) ?? $text;
        }

        if ($options->tatweel) {
            $text = preg_replace(self::TATWEEL, '', $text) ?? $text;
        }

        if ($options->punctuation) {
            $text = preg_replace(self::PUNCTUATION, '', $text) ?? $text;
        }

        if ($options->stripHamza) {
            $text = preg_replace(self::HAMZA_TO_STRIP, '', $text) ?? $text;
            $text = preg_replace(self::ALEF_MAQSURA, 'ي', $text) ?? $text;
            $text = preg_replace(self::UTHMANI_WAW_TA, 'اة', $text) ?? $text;
            $text = preg_replace(self::DOUBLE_YA, 'ي', $text) ?? $text;
            $text = preg_replace(self::SAD_VARIANT, 'بسط', $text) ?? $text;
            $text = preg_replace(self::SIN_VARIANT, 'سيطر', $text) ?? $text;
            $text = preg_replace(self::DOUBLE_LAM, '$1ل', $text) ?? $text;
        }

        if ($options->collapseWhitespace) {
            $text = trim(preg_replace(self::MULTI_WHITESPACE, ' ', $text) ?? $text);
        }

        return $text;
    }

    /**
     * Normalize accepted spelling and spacing variants in the Quran corpus.
     *
     * @param string $text Normalized Quran text.
     *
     * @return string Text normalized for corpus matching.
     */
    private function normalizeQuranVariants(string $text): string
    {
        $text = str_replace('الرحمان', 'الرحمن', $text);
        $text = str_replace(['يا ادم', 'يا يها'], ['ياادم', 'يايها'], $text);

        return preg_replace(self::STANDALONE_AYYUHA, '$1يايها', $text) ?? $text;
    }

    /**
     * Normalize Arabic text for comparison.
     *
     * Uses NFKC decomposition, bidi control stripping, and configurable
     * normalization rules.
     *
     * @param string $text Arabic text to normalize.
     * @param NormalizeOptions|null $options Normalization options.
     *
     * @return string Normalized text.
     */
    public function normalize(string $text, ?NormalizeOptions $options = null): string
    {
        $this->assertUtf8($text);

        /** @noinspection PhpMultipleClassDeclarationsInspection */
        // NFKC decomposes Arabic presentation forms such as ﷲ and ﻻ.
        $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);
        $text = $normalized === false ? $text : $normalized;

        // Controls never carry Quran text and are removed regardless of options.
        $text = preg_replace(self::BIDI_CONTROLS, '', $text) ?? $text;

        return $this->normalizeCore($text, $options ?? new NormalizeOptions());
    }

    /**
     * Normalize Arabic text for Quran corpus matching.
     *
     * Applies Quran-specific normalization for spelling and spacing differences
     * in the bundled Uthmani/Imlaei dataset.
     *
     * @param string $text Arabic text to normalize for matching.
     *
     * @return string Normalized matching text.
     */
    public function normalizeForMatching(string $text): string
    {
        $text = $this->normalize($text, new NormalizeOptions(stripHamza: true));

        return $this->normalizeQuranVariants($text);
    }

    /**
     * Remove Arabic diacritics while preserving base letters.
     *
     * Removes vowel marks, shadda, and sukun, and normalizes equivalent alef,
     * yeh, and kaf forms as defined by the upstream normalization rules.
     *
     * @param string $text Arabic text from which to remove diacritics.
     *
     * @return string Text without diacritics.
     */
    public function removeDiacritics(string $text): string
    {
        $this->assertUtf8($text);

        return $this->normalizeCore($text, new NormalizeOptions(
            diacritics: true,
            markers: false,
            verseNumbers: false,
            tatweel: false,
            smallLetters: false,
            punctuation: false,
            collapseWhitespace: false,
        ));
    }

    /**
     * Check whether text contains Arabic characters.
     *
     * @param string $text Text that may contain Arabic characters.
     *
     * @return bool Whether Arabic content was found.
     */
    public function containsArabic(string $text): bool
    {
        $this->assertUtf8($text);

        return preg_match(self::ARABIC_CHARACTER, $text) === 1;
    }

    /**
     * Extract Arabic text segments from mixed text.
     *
     * @param string $text Text that may contain Arabic and non-Arabic content.
     *
     * @return list<ArabicSegment> Segments with text and character-position
     *     information.
     */
    public function extractArabicSegments(string $text): array
    {
        $this->assertUtf8($text);
        preg_match_all(self::ARABIC_SEGMENT, $text, $matches, PREG_OFFSET_CAPTURE);
        $segments = [];

        foreach ($matches[0] as [$matched, $byteOffset]) {
            $byteOffset = (int) $byteOffset;
            $segment = trim($matched);
            $segments[] = new ArabicSegment(
                $segment,
                mb_strlen(substr($text, 0, $byteOffset)),
                mb_strlen(substr($text, 0, $byteOffset + strlen($matched))),
            );
        }

        return $segments;
    }

    /**
     * Reject invalid UTF-8 because PHP strings are raw bytes, unlike Python's
     * Unicode-only str type.
     *
     * @param string $text Text to validate.
     *
     * @throws InvalidUtf8 When the text is not valid UTF-8.
     */
    private function assertUtf8(string $text): void
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidUtf8('Input must be valid UTF-8.');
        }
    }
}
