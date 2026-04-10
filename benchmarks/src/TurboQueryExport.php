<?php

declare(strict_types=1);

namespace Benchmarks;

use Illuminate\Database\Eloquent\Model;
use TurboExcel\Concerns\FromQuery;
use TurboExcel\Concerns\WithChunkSize;

class User extends Model
{
    protected $guarded = [];
}

class TurboQueryExport implements FromQuery, WithChunkSize
{
    public function query()
    {
        return User::query();
    }

    public function chunkSize(): int
    {
        return 5000;
    }
}
