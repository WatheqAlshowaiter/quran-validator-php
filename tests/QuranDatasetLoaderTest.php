<?php

declare(strict_types=1);

namespace Watheq\QuranValidator\Tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use Watheq\QuranValidator\Data\QuranDatasetLoader;
use Watheq\QuranValidator\Exceptions\InvalidDataset;

final class QuranDatasetLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testRejectsWrongDatasetSize(): void
    {
        $this->expectException(InvalidDataset::class);
        $this->expectExceptionMessage('The dataset must contain exactly 6,236 verses across 114 surahs.');

        (new QuranDatasetLoader($this->writeJson([]), $this->writeJson([])))->load();
    }

    public function testRejectsInvalidSurahStructure(): void
    {
        [$verses, $surahs] = $this->canonicalRows();
        $surahs[0]['number'] = 0;

        $this->expectException(InvalidDataset::class);
        $this->expectExceptionMessage('Invalid surah dataset structure.');

        (new QuranDatasetLoader($this->writeJson($verses), $this->writeJson($surahs)))->load();
    }

    public function testRejectsInvalidVerseStructure(): void
    {
        [$verses, $surahs] = $this->canonicalRows();
        unset($verses[0]['id']);

        $this->expectException(InvalidDataset::class);
        $this->expectExceptionMessage('Invalid verse dataset structure.');

        (new QuranDatasetLoader($this->writeJson($verses), $this->writeJson($surahs)))->load();
    }

    public function testRejectsDuplicateVerseReference(): void
    {
        [$verses, $surahs] = $this->canonicalRows();
        $verses[1]['surah'] = $verses[0]['surah'];
        $verses[1]['ayah'] = $verses[0]['ayah'];

        $this->expectException(InvalidDataset::class);
        $this->expectExceptionMessage('The verse dataset contains a duplicate reference or invalid UTF-8.');

        (new QuranDatasetLoader($this->writeJson($verses), $this->writeJson($surahs)))->load();
    }

    public function testRejectsMismatchedSurahCounts(): void
    {
        [$verses, $surahs] = $this->canonicalRows();
        $verses[0]['surah'] = 2;
        $verses[0]['ayah'] = 287;

        $this->expectException(InvalidDataset::class);
        $this->expectExceptionMessage('Verse counts do not match the surah metadata.');

        (new QuranDatasetLoader($this->writeJson($verses), $this->writeJson($surahs)))->load();
    }

    public function testRejectsNonSequentialVerseReferences(): void
    {
        [$verses, $surahs] = $this->canonicalRows();
        $verses[0]['ayah'] = 8;

        $this->expectException(InvalidDataset::class);
        $this->expectExceptionMessage('Verse references must be sequential within each surah.');

        (new QuranDatasetLoader($this->writeJson($verses), $this->writeJson($surahs)))->load();
    }

    public function testRejectsInvalidJson(): void
    {
        $invalid = $this->write('{');

        $this->expectException(InvalidDataset::class);
        $this->expectExceptionMessage('Invalid JSON in dataset file:');

        (new QuranDatasetLoader($invalid, $invalid))->load();
    }

    public function testRejectsNonListJson(): void
    {
        $object = $this->write('{"dataset":true}');

        $this->expectException(InvalidDataset::class);
        $this->expectExceptionMessage('Dataset root must be a JSON list:');

        (new QuranDatasetLoader($object, $object))->load();
    }

    /** @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     * @throws JsonException
     */
    private function canonicalRows(): array
    {
        /** @var list<array<string, mixed>> $verses */
        $verses = json_decode((string) file_get_contents(dirname(__DIR__).'/data/quran-verses.min.json'), true, 512, JSON_THROW_ON_ERROR);
        /** @var list<array<string, mixed>> $surahs */
        $surahs = json_decode((string) file_get_contents(dirname(__DIR__).'/data/quran-surahs.min.json'), true, 512, JSON_THROW_ON_ERROR);

        return [$verses, $surahs];
    }

    /** @throws JsonException */
    private function writeJson(mixed $data): string
    {
        return $this->write(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function write(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'quran-validator-');
        if ($file === false || file_put_contents($file, $contents) === false) {
            self::fail('Unable to create temporary dataset file.');
        }

        $this->temporaryFiles[] = $file;

        return $file;
    }
}
