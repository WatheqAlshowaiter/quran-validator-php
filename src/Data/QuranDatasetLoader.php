<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\Data;

use JsonException;
use Watheq\QuranValidator\Exceptions\DatasetFileMissing;
use Watheq\QuranValidator\Exceptions\InvalidDataset;
use Watheq\QuranValidator\ValueObjects\QuranSurah;
use Watheq\QuranValidator\ValueObjects\QuranVerse;

final readonly class QuranDatasetLoader
{
    public function __construct(
        private string $versesFile,
        private string $surahsFile,
    ) {
    }

    /** @return array{verses: list<QuranVerse>, surahs: list<QuranSurah>, surahCounts: array<int, int>} */
    public function load(): array
    {
        $verseRows = $this->decode($this->versesFile);
        $surahRows = $this->decode($this->surahsFile);

        if (count($verseRows) !== 6236 || count($surahRows) !== 114) {
            throw new InvalidDataset('The dataset must contain exactly 6,236 verses across 114 surahs.');
        }

        $surahCounts = [];
        $surahs = [];
        foreach ($surahRows as $row) {
            if (!is_array($row) || !isset($row['number'], $row['name'], $row['englishName'], $row['versesCount'], $row['revelationType'])
                || !is_int($row['number']) || !is_string($row['name']) || !is_string($row['englishName'])
                || !is_int($row['versesCount']) || !is_string($row['revelationType'])
                || $row['number'] < 1 || $row['number'] > 114 || $row['versesCount'] < 1 || isset($surahCounts[$row['number']])) {
                throw new InvalidDataset('Invalid surah dataset structure.');
            }
            $surahCounts[$row['number']] = $row['versesCount'];
            $surahs[] = new QuranSurah(
                $row['number'],
                $row['name'],
                $row['englishName'],
                $row['versesCount'],
                $row['revelationType'],
            );
        }

        $verses = [];
        $seen = [];
        $actualCounts = [];
        foreach ($verseRows as $row) {
            if (!is_array($row)
                || !isset($row['id'], $row['surah'], $row['ayah'], $row['text'], $row['textSimple'])
                || !is_int($row['id']) || !is_int($row['surah']) || !is_int($row['ayah'])
                || !is_string($row['text']) || !is_string($row['textSimple'])) {
                throw new InvalidDataset('Invalid verse dataset structure.');
            }

            $reference = $row['surah'].':'.$row['ayah'];
            if (isset($seen[$reference]) || !mb_check_encoding($row['text'], 'UTF-8')) {
                throw new InvalidDataset('The verse dataset contains a duplicate reference or invalid UTF-8.');
            }

            $seen[$reference] = true;
            $actualCounts[$row['surah']] = ($actualCounts[$row['surah']] ?? 0) + 1;
            $verses[] = new QuranVerse($row['id'], $row['surah'], $row['ayah'], $row['text'], $row['textSimple']);
        }

        if ($actualCounts !== $surahCounts) {
            throw new InvalidDataset('Verse counts do not match the surah metadata.');
        }

        foreach ($surahCounts as $surah => $count) {
            for ($ayah = 1; $ayah <= $count; ++$ayah) {
                if (!isset($seen[$surah.':'.$ayah])) {
                    throw new InvalidDataset('Verse references must be sequential within each surah.');
                }
            }
        }

        return ['verses' => $verses, 'surahs' => $surahs, 'surahCounts' => $surahCounts];
    }

    /** @return list<mixed> */
    private function decode(string $file): array
    {
        if (!is_file($file)) {
            throw new DatasetFileMissing(sprintf('Dataset file not found: %s', $file));
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidDataset(sprintf('Invalid JSON in dataset file: %s', $file), previous: $exception);
        }

        if (!is_array($data) || !array_is_list($data)) {
            throw new InvalidDataset(sprintf('Dataset root must be a JSON list: %s', $file));
        }

        return $data;
    }
}
