<?php

declare(strict_types=1);

namespace FastExcel\Concerns;

interface WithChunkSize
{
    /**
     * Number of rows to load per database query when using {@see FromQuery}.
     *
     * Defaults to the value of `config('fast-excel.chunk_size')` (1000).
     */
    public function chunkSize(): int;
}
