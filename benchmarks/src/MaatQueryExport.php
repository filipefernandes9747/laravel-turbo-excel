<?php

declare(strict_types=1);

namespace Benchmarks;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithChunkSize;

class MaatQueryExport implements FromQuery, WithChunkSize
{
    use Exportable;

    public function query()
    {
        return User::query();
    }

    public function chunkSize(): int
    {
        return 5000;
    }
}
