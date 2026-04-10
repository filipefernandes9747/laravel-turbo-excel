<?php

declare(strict_types=1);

namespace TurboExcel\Import;

use Illuminate\Support\Collection;

final class Result
{
    /**
     * @param  Collection<int, array<string, mixed>>|null  $rows  Set when the import implements {@see \TurboExcel\Import\Concerns\ToCollection}
     */
    public function __construct(
        public readonly int $processed,
        public readonly int $failed,
        public readonly ?Collection $rows = null,
    ) {}
}
