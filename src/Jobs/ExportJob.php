<?php

declare(strict_types=1);

namespace TurboExcel\Jobs;

use TurboExcel\Enums\Format;
use TurboExcel\TurboExcel;
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

        // We use the TurboExcel service just to get the temporary path and format logic.
        $turboExcel = app(TurboExcel::class);
        $format = $this->format ?? Format::fromFilename($this->filePath);
        $tmpPath = $this->getTempPath($format->extension());

        try {
            $exporter = new \TurboExcel\Exporter($this->export, $format);
            
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
        $dir = storage_path('app/turbo-excel-tmp');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, recursive: true);
        }

        return $dir . DIRECTORY_SEPARATOR . uniqid('turbo-excel-job-', true) . '.' . $extension;
    }

    // Helper for testing or future use
    public function setBatch($batch)
    {
        $this->batchId = $batch->id;
    }
}
