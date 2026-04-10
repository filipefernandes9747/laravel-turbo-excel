<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

interface WithHeaderValidation
{
    /**
     * Validation rules for the header row (keys = normalized header names).
     *
     * @return array<string, mixed>
     */
    public function headers(): array;
}
