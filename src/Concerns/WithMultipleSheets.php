<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

interface WithMultipleSheets
{
    /**
     * @return object[]
     */
    public function sheets(): array;
}
