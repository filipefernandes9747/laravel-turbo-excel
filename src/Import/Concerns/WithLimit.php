<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

interface WithLimit
{
    /**
     * Limit the number of rows to be imported.
     */
    public function limit(): int;
}
