<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

interface WithMapping
{
    /**
     * @param  mixed  $row
     * @return array<string, mixed>
     */
    public function map(mixed $row): array;
}
