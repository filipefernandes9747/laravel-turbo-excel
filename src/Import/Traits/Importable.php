<?php

declare(strict_types=1);

namespace TurboExcel\Import\Traits;

use Illuminate\Bus\Batch;
use TurboExcel\Enums\Format;
use TurboExcel\Facades\TurboExcel;
use TurboExcel\Import\Concerns\WithMetrics;
use TurboExcel\Import\Result;

trait Importable
{
    private bool $withMetrics = false;

    /**
     * @return Result|Batch
     */
    public function import(string $path, ?Format $format = null): Result|Batch
    {
        return TurboExcel::import($this, $path, $format);
    }

    public function queue(string $path, ?Format $format = null): Batch
    {
        // Internal check to ensure it returns a Batch
        return TurboExcel::import($this, $path, $format);
    }

    public function withMetrics(): self
    {
        $this->withMetrics = true;

        return $this;
    }

    public function isMetricsEnabled(): bool
    {
        return $this->withMetrics || $this instanceof WithMetrics;
    }
}
