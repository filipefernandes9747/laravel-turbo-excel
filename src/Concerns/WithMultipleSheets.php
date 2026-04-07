<?php

declare(strict_types=1);

namespace FastExcel\Concerns;

interface WithMultipleSheets
{
    /**
     * Return an array of sheet export objects.
     *
     * Each object may implement any combination of the other concerns
     * (FromArray, FromQuery, WithTitle, WithHeadings, WithMapping, …).
     *
     * Only supported for XLSX. A {@see \FastExcel\Exceptions\FastExcelException}
     * is thrown if this concern is used with CSV.
     *
     * @return object[]
     */
    public function sheets(): array;
}
