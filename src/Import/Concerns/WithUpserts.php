<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

interface WithUpserts
{
    /**
     * The columns that uniquely identify records for upserts.
     *
     * @return array<string>|string
     */
    public function uniqueBy(): array|string;
}
