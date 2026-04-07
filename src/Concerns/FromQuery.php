<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

interface FromQuery
{
    public function query(): Builder|QueryBuilder;
}
