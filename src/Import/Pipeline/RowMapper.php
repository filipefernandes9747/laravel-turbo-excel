<?php

declare(strict_types=1);

namespace TurboExcel\Import\Pipeline;

use TurboExcel\Import\Concerns\WithMapping;

final class RowMapper
{
    /**
     * @param  array<int|string, mixed>  $row
     * @return array<int|string, mixed>
     */
    public static function map(object $import, array $row): array
    {
        if ($import instanceof WithMapping) {
            return $import->map($row);
        }

        return $row;
    }
}
