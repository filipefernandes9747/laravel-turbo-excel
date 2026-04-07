<?php

declare(strict_types=1);

namespace FastExcel\Jobs;

use FastExcel\Enums\Format;
use FastExcel\FastExcel;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ExportJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Delete the job if its models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public object $export,
        public string $filePath,
        public string $disk = 'local',
        public ?Format $format = null,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // We use the FastExcel service just to get the temporary path and format logic.
        $fastExcel = app(FastExcel::class);
        $format = $this->format ?? Format::fromFilename($this->filePath);
        $tmpPath = $this->getTempPath($format->extension());

        try {
            $totalRows = $this->calculateTotalRows();
            $processedCount = 0;

            $exporter = new \FastExcel\Exporter($this->export, $format);
            
            // We can add a custom progress callback if we implement standard Events later,
            // but Laravel Batch does not natively support intra-job progress updating.
            $exporter->onProgress(function () {
                // Future event dispatching could happen here.
            });

            // Export to our local temporary file
            $exporter->export($tmpPath);

            // Once fully exported, move from temp strictly to the target Laravel disk
            if ($this->batch()?->cancelled()) {
                return;
            }

            // We must use putFileAs with a stream to avoid loading it into memory
            $stream = fopen($tmpPath, 'rb');
            Storage::disk($this->disk)->put($this->filePath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

        } finally {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    private function getTempPath(string $extension): string
    {
        $dir = storage_path('app/fast-excel-tmp');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, recursive: true);
        }

        return $dir . DIRECTORY_SEPARATOR . uniqid('fast-excel-job-', true) . '.' . $extension;
    }

    private function calculateTotalRows(): int
    {
        if ($this->export instanceof \FastExcel\Concerns\FromQuery) {
            return $this->export->query()->count();
        }

        if ($this->export instanceof \FastExcel\Concerns\FromCollection) {
            return $this->export->collection()->count();
        }

        if ($this->export instanceof \FastExcel\Concerns\FromArray) {
            return count($this->export->array());
        }

        return 0; // Generators typically cannot be counted efficiently beforehand
    }
}
