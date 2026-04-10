<?php

declare(strict_types=1);

namespace TurboExcel\Import\Traits;

trait RemembersRowNumber
{
    protected int $rowNumber = 0;

    public function setRowNumber(int $rowNumber): void
    {
        $this->rowNumber = $rowNumber;
    }

    public function getRowNumber(): int
    {
        return $this->rowNumber;
    }
}
