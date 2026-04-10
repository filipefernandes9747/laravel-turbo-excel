<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

interface WithHeaderRow
{
    /**
     * 1-based index of the header row (first row after UTF-8 BOM is row 1).
     */
    public function headerRow(): int;
}
