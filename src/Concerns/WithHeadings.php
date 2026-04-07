<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

interface WithHeadings
{
    /**
     * @return array<int, string>
     */
    public function headings(): array;
}
