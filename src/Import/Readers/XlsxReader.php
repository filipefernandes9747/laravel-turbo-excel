<?php

declare(strict_types=1);

namespace TurboExcel\Import\Readers;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use TurboExcel\Import\Segments\XlsxReadSegment;

/**
 * Row indices are 1-based within the selected worksheet (first row in that sheet = 1).
 *
 * @phpstan-type RowYield array{rowIndex: int, cells: list<string>}
 */
final class XlsxReader
{
    /**
     * @return \Generator<int, RowYield>
     */
    public function rows(string $path, ?XlsxReadSegment $segment = null): \Generator
    {
        $reader = new Reader();
        $reader->open($path);

        $targetSheetIndex = $segment?->sheetIndex ?? 0;
        $currentSheetIndex = 0;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($currentSheetIndex !== $targetSheetIndex) {
                    ++$currentSheetIndex;

                    continue;
                }

                $rowIndex = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    ++$rowIndex;

                    if ($segment !== null) {
                        if ($rowIndex < $segment->startRow) {
                            continue;
                        }
                        if ($rowIndex > $segment->endRow) {
                            break;
                        }
                    }

                    yield [
                        'rowIndex' => $rowIndex,
                        'cells'    => self::indexedCells($row),
                    ];
                }

                break;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @return list<string>
     */
    public static function indexedCells(Row $row): array
    {
        $out = [];
        foreach ($row->getCells() as $cell) {
            $out[] = self::cellToString($cell);
        }

        return array_values($out);
    }

    private static function cellToString(Cell $cell): string
    {
        $value = $cell->getValue();

        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }
    }
}
