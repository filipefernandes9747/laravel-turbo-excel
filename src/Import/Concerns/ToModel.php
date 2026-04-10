<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

use Illuminate\Database\Eloquent\Model;

interface ToModel
{
    /**
     * @param  array<int|string, mixed>  $row
     */
    public function model(array $row): ?Model;
}
