<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

interface WithUpsertColumns
{
    /**
     * The columns that should be updated if a matching record already exists.
     * Return null to update all tracked columns.
     *
     * @return array<string>|null
     */
    public function upsertColumns(): ?array;
}
