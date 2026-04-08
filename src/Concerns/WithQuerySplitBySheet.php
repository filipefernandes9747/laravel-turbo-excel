<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;

interface WithQuerySplitBySheet
{
    /**
     * @return Builder|EloquentBuilder|Relation
     */
    public function query();

    /**
     * The column to split the query by. Data must be sorted by this column.
     */
    public function splitByColumn(): string;

    /**
     * Determine the handler object for the next sheet split.
     * The returned object can implement WithTitle, WithHeadings, WithMapping, etc.
     */
    public function sheet(mixed $row): object;
}
