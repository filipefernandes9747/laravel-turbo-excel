<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

interface WithLimit
{
    /**
     * Limit the number of rows to be exported.
     */
    public function limit(): int;
}
