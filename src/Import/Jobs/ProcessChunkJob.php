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
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        (new SegmentImporter())->run(
            $this->import,
            $this->filePath,
            $this->format,
            $this->segment,
            $this->headerKeys,
            $this->aggregateKey,
        );
    }
}
