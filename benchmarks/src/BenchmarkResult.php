<?php

declare(strict_types=1);

namespace Benchmarks;

final class BenchmarkResult
{
    public function __construct(
        public readonly string $label,
        public readonly int    $rows,
        public readonly float  $timeSeconds,
        public readonly int    $peakMemoryBytes,
        public readonly int    $fileSizeBytes,
    ) {}

    public function timeMs(): float
    {
        return round($this->timeSeconds * 1000, 2);
    }

    public function peakMemoryMb(): float
    {
        return round($this->peakMemoryBytes / 1024 / 1024, 2);
    }

    public function fileSizeKb(): float
    {
        return round($this->fileSizeBytes / 1024, 2);
    }
}
