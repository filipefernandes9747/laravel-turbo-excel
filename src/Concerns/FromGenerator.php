<?php

declare(strict_types=1);

namespace FastExcel\Concerns;

interface FromGenerator
{
    /**
     * Return a PHP Generator that yields one row at a time.
     *
     * Ideal for custom lazy data sources (e.g. reading a CSV, external API pages).
     * Memory usage is bounded to a single row at any given time.
     *
     * @return \Generator<int, mixed>
     */
    public function generator(): \Generator;
}
