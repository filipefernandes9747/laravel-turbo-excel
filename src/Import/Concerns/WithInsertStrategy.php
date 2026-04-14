<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

use TurboExcel\Enums\InsertStrategy;

interface WithInsertStrategy
{
    public function insertStrategy(): InsertStrategy;
}
