<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

use Throwable;

interface WithErrorHandling
{
    public function handleError(Throwable $e): void;
}
