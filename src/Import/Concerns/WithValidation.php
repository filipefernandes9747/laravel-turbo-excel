<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

interface WithValidation
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array;
}
