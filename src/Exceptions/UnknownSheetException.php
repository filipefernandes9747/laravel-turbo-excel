<?php

declare(strict_types=1);

namespace TurboExcel\Exceptions;

final class UnknownSheetException extends \Exception
{
    public static function forIndex(int $index): self
    {
        return new self("Sheet with index {$index} does not exist in the workbook.");
    }
}
