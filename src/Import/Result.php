<?php

declare(strict_types=1);

namespace TurboExcel\Import;

use Illuminate\Support\Collection;
use TurboExcel\Import\Concerns\ToCollection;

final class Result
{
    /**
     * @param  Collection<int, array<string, mixed>>|array<int, array<string, mixed>>|null  $rows  Set when the import implements {@see ToCollection} or {@see ToArray}
     */
    public function __construct(
        public readonly int $processed,
        public readonly int $failed,
        public readonly Collection|array|null $rows = null,
        public readonly float $duration = 0.0,
        public readonly float $peakMemory = 0.0,
    ) {}

    /**
     * @return array{duration: float, peak_memory: float, processed: int, failed: int, rows: Collection|null}
     */
    public function metrics(): array
    {
        return [
            'duration' => $this->duration,
            'peak_memory' => $this->peakMemory,
            'processed' => $this->processed,
            'failed' => $this->failed,
            'rows' => $this->rows,
        ];
    }
}
