<?php

declare(strict_types=1);

namespace TurboExcel\Import;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use TurboExcel\Concerns\WithEvents;
use TurboExcel\Enums\Format;
use TurboExcel\Events\AfterImport;
use TurboExcel\Events\BeforeImport;
use TurboExcel\Exceptions\TurboExcelException;
use TurboExcel\Exceptions\UnknownSheetException;
use TurboExcel\Import\Concerns\OnEachChunk;
use TurboExcel\Import\Concerns\OnEachRow;
use TurboExcel\Import\Concerns\RemembersRowNumber;
use TurboExcel\Import\Concerns\ShouldQueue as QueueImport;
use TurboExcel\Import\Concerns\SkipsEmptyRows;
use TurboExcel\Import\Concerns\SkipsOnError;
use TurboExcel\Import\Concerns\SkipsOnFailure;
use TurboExcel\Import\Concerns\SkipsUnknownSheets;
use TurboExcel\Import\Concerns\ToArray;
use TurboExcel\Import\Concerns\ToCollection;
use TurboExcel\Import\Concerns\ToModel;
use TurboExcel\Import\Concerns\WithBatchInserts;
use TurboExcel\Import\Concerns\WithChunkReading;
use TurboExcel\Import\Concerns\WithHeaderRow;
use TurboExcel\Import\Concerns\WithHeaderValidation;
use TurboExcel\Import\Concerns\WithLimit;
use TurboExcel\Import\Concerns\WithMapping;
use TurboExcel\Import\Concerns\WithMetrics;
use TurboExcel\Import\Concerns\WithMultipleSheets;
use TurboExcel\Import\Concerns\WithNormalizedHeaders;
use TurboExcel\Import\Concerns\WithProgress;
use TurboExcel\Import\Concerns\WithProgressBar;
use TurboExcel\Import\Concerns\WithStartRow;
use TurboExcel\Import\Concerns\WithUpsertColumns;
use TurboExcel\Import\Concerns\WithUpserts;
use TurboExcel\Import\Concerns\WithValidation;
use TurboExcel\Import\Jobs\ProcessChunkJob;
use TurboExcel\Import\Segments\CsvReadSegment;
use TurboExcel\Import\Segments\XlsxReadSegment;

final class Importer
{
    private const XLSX_MAX_PHYSICAL_ROW = 1_048_576;

    public function import(object $import, string $path, ?string $disk = null, ?Format $format = null): Result|Batch
    {
        $format ??= Format::fromFilename($path);

        $localizer = new Localizer;
        $isRemote = $disk !== null;
        $workingPath = $isRemote ? $localizer->localize($path, $disk) : $path;

        try {
            $resolved = realpath($workingPath);
            if ($resolved === false || ! is_readable($resolved)) {
                throw new TurboExcelException("Import file not found or unreadable: {$workingPath}");
            }
            $workingPath = $resolved;

            if ($format === Format::CSV && $import instanceof WithMultipleSheets) {
                throw new TurboExcelException('WithMultipleSheets is only supported for XLSX imports.');
            }

            $this->assertMultiSheetCoordinator($import);

            if ($import instanceof WithEvents) {
                if (isset($import->registerEvents()[BeforeImport::class])) {
                    $import->registerEvents()[BeforeImport::class](new BeforeImport($this, $import));
                }
            }

            $result = null;
            if ($import instanceof QueueImport) {
                $this->assertQueuedImportsAllowCollection($import);

                $result = $this->queueImport($import, $path, $disk, $format, $workingPath);
            } else {
                $result = $this->runSync($import, $workingPath, $format);
            }

            if ($import instanceof WithEvents) {
                if (isset($import->registerEvents()[AfterImport::class])) {
                    $resultObj = $result instanceof Result ? $result : null;
                    $import->registerEvents()[AfterImport::class](new AfterImport($this, $import, $resultObj));
                }
            }

            return $result;
        } finally {
            if ($isRemote) {
                $localizer->cleanup($workingPath);
            }
        }
    }

    private function runSync(object $import, string $path, Format $format): Result
    {
        if ($format === Format::XLSX && $import instanceof WithMultipleSheets) {
            return $this->runSyncMultiSheetXlsx($import, $path);
        }

        $totalRows = 0;
        $headerKeys = null;

        if ($import instanceof WithProgress || $import instanceof WithChunkReading || $import instanceof WithProgressBar || $this->isMetricsEnabled($import)) {
            $scan = (new ImportScanner($import, $path, $format, 1_000_000))->scan();
            $totalRows = $scan->totalRows;
            $headerKeys = $scan->headerKeys;
        }

        $progressBar = method_exists($import, 'getProgressBar') ? $import->getProgressBar() : null;
        if ($import instanceof WithProgressBar && $progressBar && $totalRows > 0) {
            $progressBar->setMaxSteps($totalRows);
            $progressBar->start();
        }

        $segmentImporter = new SegmentImporter;

        return match ($format) {
            Format::CSV => $segmentImporter->run($import, $path, $format, new CsvReadSegment(0, null), $headerKeys, null, $totalRows),
            Format::XLSX => $segmentImporter->run($import, $path, $format, null, $headerKeys, null, $totalRows),
        };
    }

    private function runSyncMultiSheetXlsx(WithMultipleSheets $import, string $path): Result
    {
        $sheets = $import->sheets();
        if ($sheets === []) {
            throw new TurboExcelException('WithMultipleSheets::sheets() must return at least one import object.');
        }

        $segmentImporter = new SegmentImporter;
        $processed = 0;
        $failed = 0;
        $duration = 0.0;
        $peakMemory = 0.0;
        $mergedRows = null;

        foreach ($sheets as $sheetIndex => $subImport) {
            if (! is_object($subImport)) {
                throw new TurboExcelException('WithMultipleSheets::sheets() must return a list of import objects.');
            }

            try {
                $result = $segmentImporter->run(
                    $subImport,
                    $path,
                    Format::XLSX,
                    new XlsxReadSegment(1, self::XLSX_MAX_PHYSICAL_ROW, $sheetIndex),
                    null,
                );
            } catch (UnknownSheetException $e) {
                if ($import instanceof SkipsUnknownSheets || $subImport instanceof SkipsUnknownSheets) {
                    continue;
                }
                throw $e;
            }

            $processed += $result->processed;
            $failed += $result->failed;
            $duration += $result->duration;
            $peakMemory = max($peakMemory, $result->peakMemory);

            if ($result->rows !== null) {
                if ($mergedRows === null) {
                    $mergedRows = $result->rows;
                } elseif (is_array($mergedRows) && is_array($result->rows)) {
                    $mergedRows = array_merge($mergedRows, $result->rows);
                } else {
                    $mergedRows = (is_array($mergedRows) ? collect($mergedRows) : $mergedRows)->concat($result->rows);
                }
            }
        }

        return new Result($processed, $failed, $mergedRows, $duration, $peakMemory);
    }

    private function queueImport(object $import, string $path, ?string $disk, Format $format, string $workingPath): Result|Batch
    {
        if ($import instanceof WithMultipleSheets) {
            return $this->queueMultiSheetXlsx($import, $path, $disk, $workingPath);
        }

        if ($import instanceof WithChunkReading) {
            $scan = (new ImportScanner(
                $import,
                $workingPath,
                $format,
                max(1, $import->chunkSize()),
            ))->scan();
            $segments = $scan->segments;
            $headerKeys = $scan->headerKeys;
        } else {
            $segments = $this->singleSegmentList($format);
            $headerKeys = null;
        }

        if ($segments === []) {
            return new Result(0, 0, null);
        }

        return $this->dispatchChunkJobs($import, $path, $disk, $format, $segments, $headerKeys, $scan->totalRows ?? 0);
    }

    private function queueMultiSheetXlsx(WithMultipleSheets $import, string $path, ?string $disk, string $workingPath): Result|Batch
    {
        $sheets = $import->sheets();
        if ($sheets === []) {
            throw new TurboExcelException('WithMultipleSheets::sheets() must return at least one import object.');
        }

        $aggregateKey = (string) Str::uuid();
        $jobs = [];

        foreach ($sheets as $sheetIndex => $subImport) {
            if (! is_object($subImport)) {
                throw new TurboExcelException('WithMultipleSheets::sheets() must return a list of import objects.');
            }

            try {
                if ($subImport instanceof WithChunkReading) {
                    $scan = (new ImportScanner(
                        $subImport,
                        $workingPath,
                        Format::XLSX,
                        max(1, $subImport->chunkSize()),
                        $sheetIndex,
                    ))->scan();
                    $segments = $scan->segments;
                    $headerKeys = $scan->headerKeys;
                } else {
                    $segments = [new XlsxReadSegment(1, self::XLSX_MAX_PHYSICAL_ROW, $sheetIndex)];
                    $headerKeys = null;
                }
            } catch (UnknownSheetException $e) {
                if ($import instanceof SkipsUnknownSheets || $subImport instanceof SkipsUnknownSheets) {
                    continue;
                }
                throw $e;
            }

            $totalRows = $scan->totalRows ?? 0;

            foreach ($segments as $segment) {
                $jobs[] = new ProcessChunkJob($subImport, $path, Format::XLSX, $segment, $headerKeys, $aggregateKey, $totalRows, $disk);
            }
        }

        if ($jobs === []) {
            return new Result(0, 0, null);
        }

        Cache::put("turbo_excel_import:{$aggregateKey}:processed", 0, 3600);
        Cache::put("turbo_excel_import:{$aggregateKey}:failed", 0, 3600);

        $batch = Bus::batch($jobs)->name('turbo-excel-import');

        $queue = property_exists($import, 'queue') ? $import->queue : (method_exists($import, 'queue') ? $import->queue() : null);
        if ($queue) {
            $batch->onQueue($queue);
        }

        return $batch->dispatch();
    }

    /**
     * @param  list<CsvReadSegment|XlsxReadSegment>  $segments
     */
    private function dispatchChunkJobs(
        object $import,
        string $path,
        ?string $disk,
        Format $format,
        array $segments,
        ?array $headerKeys,
        int $totalRows,
    ): Batch {
        $aggregateKey = (string) Str::uuid();
        Cache::put("turbo_excel_import:{$aggregateKey}:processed", 0, 3600);
        Cache::put("turbo_excel_import:{$aggregateKey}:failed", 0, 3600);

        $jobs = [];
        foreach ($segments as $segment) {
            $jobs[] = new ProcessChunkJob($import, $path, $format, $segment, $headerKeys, $aggregateKey, $totalRows, $disk);
        }

        $batch = Bus::batch($jobs)->name('turbo-excel-import');

        $queue = property_exists($import, 'queue') ? $import->queue : (method_exists($import, 'queue') ? $import->queue() : null);
        if ($queue) {
            $batch->onQueue($queue);
        }

        return $batch->dispatch();
    }

    private function assertMultiSheetCoordinator(object $import): void
    {
        if (! $import instanceof WithMultipleSheets) {
            return;
        }

        $rowLevel = [
            $import instanceof ToModel,
            $import instanceof ToCollection,
            $import instanceof ToArray,
            $import instanceof WithHeaderRow,
            $import instanceof WithHeaderValidation,
            $import instanceof WithNormalizedHeaders,
            $import instanceof WithMapping,
            $import instanceof WithValidation,
            $import instanceof SkipsOnError,
            $import instanceof SkipsOnFailure,
            $import instanceof WithBatchInserts,
            $import instanceof WithChunkReading,
            $import instanceof OnEachRow,
            $import instanceof OnEachChunk,
            $import instanceof WithUpserts,
            $import instanceof WithUpsertColumns,
            $import instanceof SkipsEmptyRows,
            $import instanceof WithEvents,
            $import instanceof WithStartRow,
            $import instanceof WithLimit,
            $import instanceof WithMetrics,
            $import instanceof RemembersRowNumber,
        ];

        if (in_array(true, $rowLevel, true)) {
            throw new TurboExcelException(
                'When using WithMultipleSheets, define ToModel, ToCollection, headers, mapping, validation, and chunk settings on each sheet import from sheets(), not on the coordinator class.',
            );
        }
    }

    private function assertQueuedImportsAllowCollection(object $import): void
    {
        $targets = $import instanceof WithMultipleSheets ? $import->sheets() : [$import];

        foreach ($targets as $sub) {
            if ($sub instanceof ToCollection) {
                throw new TurboExcelException(
                    'ToCollection is only supported for synchronous imports. Remove TurboExcel\\Import\\Concerns\\ShouldQueue, or use ToModel instead.',
                );
            }
        }
    }

    /**
     * @return list<CsvReadSegment|XlsxReadSegment>
     */
    private function singleSegmentList(Format $format): array
    {
        return match ($format) {
            Format::CSV => [new CsvReadSegment(0, null)],
            Format::XLSX => [new XlsxReadSegment(1, self::XLSX_MAX_PHYSICAL_ROW, 0)],
        };
    }

    private function isMetricsEnabled(object $import): bool
    {
        if ($import instanceof WithMetrics) {
            return true;
        }

        if (method_exists($import, 'isMetricsEnabled')) {
            return (bool) $import->isMetricsEnabled();
        }

        return false;
    }
}
