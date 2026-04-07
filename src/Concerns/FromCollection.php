<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

use Illuminate\Support\Collection;

interface FromCollection
{
    /**
     * @return Collection<array-key, mixed>
     */
    public function collection(): Collection;
}
