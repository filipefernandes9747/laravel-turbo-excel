<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

interface WithCsvOptions
{
    /**
     * Define the field delimiter (default: ',').
     */
    public function delimiter(): string;

    /**
     * Define the field enclosure (default: '"').
     */
    public function enclosure(): string;

    /**
     * Whether to add a Byte Order Mark (BOM) to the CSV (default: false).
     */
    public function addBom(): bool;
}
