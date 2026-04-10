<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

interface OnEachRow
{
    /**
     * Process an individual row after it has been mapped and validated.
     *
     * @param  array<string, mixed>  $row
     */
    public function onRow(array $row): void;
}
