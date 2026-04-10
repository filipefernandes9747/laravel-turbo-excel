<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

interface WithMapping
{
    /**
     * @param  array<int|string, mixed>  $row
     * @return array<string, mixed>
     */
    public function map(array $row): array;
}
