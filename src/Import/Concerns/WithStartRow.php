<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

interface WithStartRow
{
    /**
     * The row number where the data starts (1-based).
     */
    public function startRow(): int;
}
