<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

interface WithMapping
{
    /**
     * @return array<string, mixed>
     */
    public function map(mixed $row): array;
}
