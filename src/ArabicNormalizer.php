<?php

declare(strict_types=1);

namespace Watheq\QuranValidator;

use Watheq\QuranValidator\Contracts\ArabicNormalizerInterface;
use Watheq\QuranValidator\Exceptions\InvalidUtf8;
use Watheq\QuranValidator\ValueObjects\ArabicSegment;

final class ArabicNormalizer implements ArabicNormalizerInterface
{
    /**
     * @var string
     */
    private const ARABIC_CHARACTER_PATTERN = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u';
    /**
     * @var string
     */
    private const ARABIC_SEGMENT_PATTERN = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}][\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}\s]*/u';

    public function normalize(string $text): string
    {
        $this->assertUtf8($text);
        $text = str_replace(['ﷲ', "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{061C}"], ['الله', '', '', '', '', ''], $text);
        $text = $this->removeDiacritics($text);
        $text = str_replace(['آ', 'ٱ', 'ٲ', 'ٳ', 'ی', 'ے', 'ک'], ['ا', 'ا', 'ا', 'ا', 'ي', 'ي', 'ك'], $text);
        $text = preg_replace('/ا?\x{0670}/u', 'ا', $text) ?? $text;
        $text = preg_replace('/[\x{06D6}-\x{06ED}\x{FD3E}\x{FD3F}\x{0640}]/u', '', $text) ?? $text;
        $text = preg_replace('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', '', $text) ?? $text;
        $text = preg_replace('/[.,;:!?…،؛؟]/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    public function normalizeForMatching(string $text): string
    {
        $text = $this->normalize($text);
        $text = str_replace(['ء', 'أ', 'إ', 'ئ'], '', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = preg_replace('/وا?ة/u', 'اة', $text) ?? $text;
        $text = str_replace(['يي', 'بصط', 'صيطر', 'الرحمان'], ['ي', 'بسط', 'سيطر', 'الرحمن'], $text);
        $text = str_replace(['يا ادم', 'يا يها'], ['ياادم', 'يايها'], $text);
        $text = preg_replace('/(^| )يها(?= |$)/u', '$1يايها', $text) ?? $text;

        return preg_replace('/الل/u', 'ال', $text) ?? $text;
    }

    public function removeDiacritics(string $text): string
    {
        $this->assertUtf8($text);

        return preg_replace('/[\x{064B}-\x{065F}]/u', '', $text) ?? $text;
    }

    public function containsArabic(string $text): bool
    {
        $this->assertUtf8($text);

        return preg_match(self::ARABIC_CHARACTER_PATTERN, $text) === 1;
    }

    public function extractArabicSegments(string $text): array
    {
        $this->assertUtf8($text);
        preg_match_all(self::ARABIC_SEGMENT_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE);
        $segments = [];

        foreach ($matches[0] as [$matched, $byteOffset]) {
            $byteOffset = (int) $byteOffset;
            $segment = preg_replace('/^\s+|\s+$/u', '', $matched) ?? $matched;
            $segments[] = new ArabicSegment(
                $segment,
                mb_strlen(substr($text, 0, $byteOffset)),
                mb_strlen(substr($text, 0, $byteOffset + strlen($matched))),
            );
        }

        return $segments;
    }

    private function assertUtf8(string $text): void
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidUtf8('Input must be valid UTF-8.');
        }
    }
}
