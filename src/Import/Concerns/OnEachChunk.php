<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

use Illuminate\Support\Collection;

interface OnEachChunk
{
    /**
     * Process a chunk of rows after they have been mapped and validated.
     *
     * @param  Collection<int, array<string, mixed>>  $chunk
     */
    public function onChunk(Collection $chunk): void;
}
