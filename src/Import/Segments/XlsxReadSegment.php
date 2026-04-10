<?php

declare(strict_types=1);

namespace TurboExcel\Import\Segments;

/**
 * 1-based inclusive row indices on a single worksheet.
 * {@see $endRow} is inclusive. {@see $sheetIndex} is 0-based (first sheet = `0`).
 */
final class XlsxReadSegment
{
    public function __construct(
        public readonly int $startRow,
        public readonly int $endRow,
        public readonly int $sheetIndex = 0,
    ) {
        if ($this->startRow < 1 || $this->endRow < $this->startRow) {
            throw new \InvalidArgumentException('Invalid XLSX row range.');
        }
        if ($this->sheetIndex < 0) {
            throw new \InvalidArgumentException('sheetIndex must be >= 0.');
        }
    }
}
