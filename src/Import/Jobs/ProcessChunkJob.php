<?php

declare(strict_types=1);

namespace TurboExcel\Import\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use TurboExcel\Enums\Format;
use TurboExcel\Import\Localizer;
use TurboExcel\Import\SegmentImporter;
use TurboExcel\Import\Segments\CsvReadSegment;
use TurboExcel\Import\Segments\XlsxReadSegment;

class ProcessChunkJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<string>|null  $headerKeys
     */
    public function __construct(
        public object $import,
        public string $filePath,
        public Format $format,
        public CsvReadSegment|XlsxReadSegment $segment,
        public ?array $headerKeys,
        public string $aggregateKey,
        public int $totalRows,
        public ?string $disk = null,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $localizer = new Localizer;
        $isRemote = $this->disk !== null;
        $workingPath = $isRemote ? $localizer->localize($this->filePath, $this->disk) : $this->filePath;

        try {
            (new SegmentImporter)->run(
                $this->import,
                $workingPath,
                $this->format,
                $this->segment,
                $this->headerKeys,
                $this->aggregateKey,
                $this->totalRows,
            );
        } finally {
            if ($isRemote) {
                $localizer->cleanup($workingPath);
            }
        }
    }
}
