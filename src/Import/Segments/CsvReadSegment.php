<?php

declare(strict_types=1);

namespace TurboExcel\Import\Segments;

/**
 * Byte range in the raw CSV file. {@see $endByte} is exclusive; null means read until EOF.
 * Boundaries are taken after UTF-8 BOM consumption (logical stream starts at byte 0 = first row byte).
 */
final class CsvReadSegment
{
    public function __construct(
        public readonly int $startByte,
        public readonly ?int $endByte = null,
    ) {
        if ($this->startByte < 0) {
            throw new \InvalidArgumentException('startByte must be >= 0.');
        }
        if ($this->endByte !== null && $this->endByte < $this->startByte) {
            throw new \InvalidArgumentException('endByte must be >= startByte.');
        }
    }
}
