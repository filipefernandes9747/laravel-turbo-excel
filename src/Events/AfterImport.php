<?php

declare(strict_types=1);

namespace TurboExcel\Events;

use TurboExcel\Import\Importer;
use TurboExcel\Import\Result;

final class AfterImport
{
    public function __construct(
        public readonly Importer $importer,
        public readonly object $import,
        public readonly ?Result $result
    ) {}
}
