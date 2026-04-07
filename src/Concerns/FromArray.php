<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

interface FromArray
{
    /**
     * @return array<array-key, mixed>
     */
    public function array(): array;
}
