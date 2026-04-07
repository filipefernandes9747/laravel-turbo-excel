<?php

declare(strict_types=1);

namespace FastExcel\Concerns;

interface WithMapping
{
    /**
     * Transform a single row before it is written.
     *
     * The returned array's values become the cell values.
     * Combine with {@see WithHeadings} to provide column labels.
     *
     * @param  mixed  $row  The raw item from the data source.
     * @return array<int, mixed>
     */
    public function map(mixed $row): array;
}
