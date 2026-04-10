<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

interface WithChunkReading
{
    public function chunkSize(): int;
}
