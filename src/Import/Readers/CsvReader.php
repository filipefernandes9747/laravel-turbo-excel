<?php

declare(strict_types=1);

namespace TurboExcel\Import\Readers;

/**
 * Streams CSV rows as 0-indexed cell arrays. UTF-8 BOM is stripped only when reading from offset 0.
 *
 * @phpstan-type RowYield array{rowIndex: int, cells: list<string>}
 */
final class CsvReader
{
    private const UTF8_BOM = "\xEF\xBB\xBF";

    public function __construct(
        private readonly string $path,
        private readonly string $delimiter = ',',
        private readonly string $enclosure = '"',
        private readonly string $escape = '\\',
    ) {}

    /**
     * @return \Generator<int, array{rowIndex: int, cells: list<string>}>
     */
    public function iterateByByteOffset(int $startByte, ?int $endByte, int $startRowIndex = 1): \Generator
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV: {$this->path}");
        }

        try {
            if ($startByte > 0) {
                fseek($handle, $startByte);
            } else {
                self::skipUtf8BomIfAtStart($handle);
            }

            $rowIndex = $startRowIndex;

            while (! feof($handle)) {
                $posBefore = ftell($handle);
                if ($posBefore === false) {
                    break;
                }
                if ($endByte !== null && $posBefore >= $endByte) {
                    break;
                }

                $line = fgetcsv($handle, 0, $this->delimiter, $this->enclosure, $this->escape);
                if ($line === false) {
                    break;
                }

                /** @var array<int, string|null> $line */
                $cells = [];
                foreach ($line as $cell) {
                    $cells[] = $cell === null ? '' : (string) $cell;
                }
                $cells = array_values($cells);

                yield [
                    'rowIndex' => $rowIndex,
                    'cells'    => $cells,
                ];

                ++$rowIndex;

                $posAfter = ftell($handle);
                if ($endByte !== null && $posAfter !== false && $posAfter >= $endByte) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    public static function skipUtf8BomIfAtStart($handle): void
    {
        if (ftell($handle) !== 0) {
            return;
        }

        $prefix = fread($handle, 3);
        if ($prefix === self::UTF8_BOM) {
            return;
        }

        rewind($handle);
    }
}
