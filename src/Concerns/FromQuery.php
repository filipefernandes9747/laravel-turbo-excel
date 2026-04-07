<?php

declare(strict_types=1);

namespace FastExcel\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

interface FromQuery
{
    /**
     * Return the query builder to export from.
     *
     * Rows are streamed via lazy() so memory use stays flat regardless of dataset size.
     * Combine with {@see WithChunkSize} to control the chunk size (default: 1000).
     */
    public function query(): EloquentBuilder|QueryBuilder;
}
