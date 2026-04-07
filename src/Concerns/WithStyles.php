<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

use OpenSpout\Common\Entity\Style\Style;

interface WithStyles
{
    /**
     * Define styles for rows or columns.
     *
     * Keys can be:
     * - Column index (0, 1, 2)
     * - Column letter (A, B, C)
     * - String 'header' to style the first row.
     *
     * @return array<int|string, Style>
     */
    public function styles(): array;
}
