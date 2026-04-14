<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

interface WithColumnWidths
{
    /**
     * @return array<string|int, float> e.g. ['A' => 45, 'B' => 15] or [0 => 45]
     */
    public function columnWidths(): array;
}
