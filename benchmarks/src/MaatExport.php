<?php

declare(strict_types=1);

namespace Benchmarks;

use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\Exportable;

class MaatExport implements FromGenerator
{
    use Exportable;

    public function __construct(private int $rows) {}

    public function generator(): \Generator
    {
        for ($i = 1; $i <= $this->rows; $i++) {
            yield [
                'id' => $i,
                'name' => 'User ' . $i,
                'email' => "user{$i}@example.com",
                'status' => $i % 2 === 0 ? 'active' : 'inactive',
                'created_at' => '2023-10-01 10:00:00',
            ];
        }
    }
}
