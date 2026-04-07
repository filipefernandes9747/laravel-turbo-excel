<?php

declare(strict_types=1);

namespace FastExcel\Concerns;

use Illuminate\Support\Collection;

interface FromCollection
{
    /**
     * Return the data as an Illuminate Collection.
     * Use for small or pre-filtered in-memory datasets.
     */
    public function collection(): Collection;
}
