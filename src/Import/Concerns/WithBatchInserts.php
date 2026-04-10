<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

interface WithBatchInserts
{
    public function batchSize(): int;
}
