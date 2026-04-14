<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

interface WithRowFilter
{
    /**
     * @param array<int|string, mixed> $row
     */
    public function filterRow(array $row): bool;
}
