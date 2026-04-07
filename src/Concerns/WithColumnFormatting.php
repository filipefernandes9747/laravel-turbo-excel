<?php

declare(strict_types=1);

namespace FastExcel\Concerns;

interface WithColumnFormatting
{
    /**
     * Define column formats (for XLSX only).
     *
     * Keys can be:
     * - Column index (0, 1, 2)
     * - Column letter (A, B, C)
     *
     * Values must be a valid XLSX format string (e.g. '#,##0.00').
     *
     * @return array<int|string, string>
     */
    public function columnFormats(): array;
}
