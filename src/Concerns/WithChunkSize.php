<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

interface WithChunkSize
{
    public function chunkSize(): int;
}
