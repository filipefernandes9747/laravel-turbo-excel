<?php

declare(strict_types=1);

namespace FastExcel\Concerns;

interface WithHeadings
{
    /**
     * Return the heading row values.
     *
     * When this concern is implemented, these explicit headings are written
     * instead of auto-deriving them from the first row's array keys.
     *
     * @return array<int, string>
     */
    public function headings(): array;
}
