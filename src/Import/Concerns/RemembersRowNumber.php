<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

interface RemembersRowNumber
{
    public function setRowNumber(int $rowNumber): void;

    public function getRowNumber(): int;
}
