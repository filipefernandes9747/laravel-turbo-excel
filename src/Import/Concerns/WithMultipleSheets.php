<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

/**
 * Import multiple worksheets from one XLSX file. **XLSX only** — not supported for CSV.
 *
 * Return one import object per sheet in **workbook order** (index `0` = first worksheet, `1` = second, …).
 * Each object may implement its own {@see ToModel}, {@see ToCollection}, header concerns, validation, etc.
 *
 * The coordinator class should normally implement only this concern (and optionally {@see ShouldQueue}).
 */
interface WithMultipleSheets
{
    /**
     * @return list<object>
     */
    public function sheets(): array;
}
