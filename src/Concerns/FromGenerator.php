<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

use Generator;

interface FromGenerator
{
    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function generator(): Generator;
}
