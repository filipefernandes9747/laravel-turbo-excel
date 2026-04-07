<?php

declare(strict_types=1);

namespace FastExcel\Concerns;

interface FromArray
{
    /**
     * Return the raw data as a plain PHP array.
     * Each element must be an array or object that can be normalised to a row.
     *
     * @return array<int, mixed>
     */
    public function array(): array;
}
