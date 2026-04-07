<?php

declare(strict_types=1);

namespace FastExcel\Concerns;

interface WithTitle
{
    /**
     * The name to give the sheet tab.
     *
     * Only meaningful for XLSX exports. Ignored for CSV.
     */
    public function title(): string;
}
