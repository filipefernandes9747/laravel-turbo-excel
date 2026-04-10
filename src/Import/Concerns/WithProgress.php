<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

interface WithProgress
{
    /**
     * Provide a unique key to store the import progress percentage (0-100) in the Cache.
     */
    public function progressKey(): string;
}
