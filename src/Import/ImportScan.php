<?php

declare(strict_types=1);

namespace TurboExcel\Import;

use TurboExcel\Import\Segments\CsvReadSegment;
use TurboExcel\Import\Segments\XlsxReadSegment;

final class ImportScan
{
    /**
     * @param  list<string>|null  $headerKeys
     * @param  list<CsvReadSegment|XlsxReadSegment>  $segments
     */
    public function __construct(
        public readonly ?array $headerKeys,
        public readonly array $segments,
        public readonly int $totalRows,
    ) {}
}
