<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

interface WithBatchSize
{
    public function batchSize(): int;
}
