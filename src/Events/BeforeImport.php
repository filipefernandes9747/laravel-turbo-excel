<?php

declare(strict_types=1);

namespace TurboExcel\Events;

use TurboExcel\Import\Importer;

final class BeforeImport
{
    public function __construct(
        public readonly Importer $importer,
        public readonly object $import
    ) {}
}
