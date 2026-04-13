<?php

declare(strict_types=1);

namespace TurboExcel\Events;

use TurboExcel\Exporter;

final class AfterExport
{
    public function __construct(
        public readonly Exporter $exporter,
        public readonly object $export
    ) {}
}
