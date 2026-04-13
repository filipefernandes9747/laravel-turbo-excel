<?php

declare(strict_types=1);

namespace TurboExcel\Events;

use TurboExcel\Exporter;

final class BeforeExport
{
    public function __construct(
        public readonly Exporter $exporter,
        public readonly object $export
    ) {}
}
