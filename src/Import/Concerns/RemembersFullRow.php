<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

interface RemembersFullRow
{
    /**
     * @param array<int|string, mixed> $row
     */
    public function setFullRow(array $row): void;
}
