<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

interface SkipsOnFailure
{
    /**
     * @param  array<int|string, mixed>  $row
     */
    public function onFailure(array $row, \Throwable $e): void;
}
