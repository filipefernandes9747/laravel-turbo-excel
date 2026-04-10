<?php

declare(strict_types=1);

namespace TurboExcel\Import;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use TurboExcel\Enums\Format;
use TurboExcel\Exceptions\TurboExcelException;
use TurboExcel\Import\Concerns\ShouldQueue as QueueImport;
use TurboExcel\Import\Concerns\OnEachChunk;
use TurboExcel\Import\Concerns\OnEachRow;
use TurboExcel\Import\Concerns\SkipsEmptyRows;
use TurboExcel\Import\Concerns\SkipsOnFailure;
use TurboExcel\Import\Concerns\ToCollection;
use TurboExcel\Import\Concerns\ToModel;
use TurboExcel\Import\Concerns\WithBatchInserts;
use TurboExcel\Import\Concerns\WithChunkReading;
use TurboExcel\Import\Concerns\WithHeaderRow;
use TurboExcel\Import\Concerns\WithHeaderValidation;
use TurboExcel\Import\Concerns\WithMapping;
use TurboExcel\Import\Concerns\WithLimit;
use TurboExcel\Import\Concerns\WithMetrics;
use TurboExcel\Import\Concerns\WithMultipleSheets;
use TurboExcel\Import\Concerns\WithNormalizedHeaders;
use TurboExcel\Import\Concerns\WithStartRow;
use TurboExcel\Import\Concerns\RemembersRowNumber;
use TurboExcel\Import\Concerns\WithUpsertColumns;
use TurboExcel\Import\Concerns\WithUpserts;
use TurboExcel\Import\Concerns\WithValidation;
use TurboExcel\Import\Jobs\ProcessChunkJob;
use TurboExcel\Import\Segments\CsvReadSegment;
use TurboExcel\Import\Segments\XlsxReadSegment;

final class Importer
{
    private const XLSX_MAX_PHYSICAL_ROW = 1_048_576;

    /**
     * @return Result|Batch
     */
    public function import(object $import, string $path, ?Format $format = null): Result|Batch
    {
        $format ??= Format::fromFilename($path);
        $resolved = realpath($path);
        if ($resolved === false || ! is_readable($resolved)) {
            throw new TurboExcelException("Import file not found or unreadable: {$path}");
        }
        $path = $resolved;

        if ($format === Format::CSV && $import instanceof WithMultipleSheets) {
            throw new TurboExcelException('WithMultipleSheets is only supported for XLSX imports.');
        }

        $this->assertMultiSheetCoordinator($import);

        if ($import instanceof QueueImport) {
            $this->assertQueuedImportsAllowCollection($import);

            return $this->queueImport($import, $path, $format);
        }

        return $this->runSync($import, $path, $format);
    }

    private function runSync(object $import, string $path, Format $format): Result
    {
        if ($format === Format::XLSX && $import instanceof WithMultipleSheets) {
            return $this->runSyncMultiSheetXlsx($import, $path);
        }

        $segmentImporter = new SegmentImporter();

        return match ($format) {
            Format::CSV => $segmentImporter->run($import, $path, $format, new CsvReadSegment(0, null), null),
            Format::XLSX => $segmentImporter->run($import, $path, $format, null, null),
        };
    }

    private function runSyncMultiSheetXlsx(WithMultipleSheets $import, string $path): Result
    {
        $sheets = $import->sheets();
        if ($sheets === []) {
            throw new TurboExcelException('WithMultipleSheets::sheets() must return at least one import object.');
        }

        $segmentImporter = new SegmentImporter();
        $processed = 0;
        $failed = 0;
        $mergedRows = null;

        foreach ($sheets as $sheetIndex => $subImport) {
            if (! is_object($subImport)) {
                throw new TurboExcelException('WithMultipleSheets::sheets() must return a list of import objects.');
            }

            $result = $segmentImporter->run(
                $subImport,
                $path,
                Format::XLSX,
                new XlsxReadSegment(1, self::XLSX_MAX_PHYSICAL_ROW, $sheetIndex),
                null,
            );

            $processed += $result->processed;
            $failed += $result->failed;

            if ($result->rows !== null) {
                $mergedRows = ($mergedRows ?? collect())->concat($result->rows);
            }
        }

        return new Result($processed, $failed, $mergedRows);
    }

    /**
     * @return Result|Batch
     */
    private function queueImport(object $import, string $path, Format $format): Result|Batch
    {
        if ($import instanceof WithMultipleSheets) {
            return $this->queueMultiSheetXlsx($import, $path);
        }

        if ($import instanceof WithChunkReading) {
            $scan = (new ImportScanner(
                $import,
                $path,
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

        return $this->dispatchChunkJobs($import, $path, $format, $segments, $headerKeys);
    }

    private function queueMultiSheetXlsx(WithMultipleSheets $import, string $path): Result|Batch
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

            if ($subImport instanceof WithChunkReading) {
                $scan = (new ImportScanner(
                    $subImport,
                    $path,
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

            foreach ($segments as $segment) {
                $jobs[] = new ProcessChunkJob($subImport, $path, Format::XLSX, $segment, $headerKeys, $aggregateKey);
            }
        }

        if ($jobs === []) {
            return new Result(0, 0, null);
        }

        Cache::put("turbo_excel_import:{$aggregateKey}:processed", 0, 3600);
        Cache::put("turbo_excel_import:{$aggregateKey}:failed", 0, 3600);

        return Bus::batch($jobs)->name('turbo-excel-import')->dispatch();
    }

    /**
     * @param  list<CsvReadSegment|XlsxReadSegment>  $segments
     */
    private function dispatchChunkJobs(
        object $import,
        string $path,
        Format $format,
        array $segments,
        ?array $headerKeys,
    ): Batch {
        $aggregateKey = (string) Str::uuid();
        Cache::put("turbo_excel_import:{$aggregateKey}:processed", 0, 3600);
        Cache::put("turbo_excel_import:{$aggregateKey}:failed", 0, 3600);

        $jobs = [];
        foreach ($segments as $segment) {
            $jobs[] = new ProcessChunkJob($import, $path, $format, $segment, $headerKeys, $aggregateKey);
        }

        return Bus::batch($jobs)->name('turbo-excel-import')->dispatch();
    }

    private function assertMultiSheetCoordinator(object $import): void
    {
        if (! $import instanceof WithMultipleSheets) {
            return;
        }

        $rowLevel = [
            $import instanceof ToModel,
            $import instanceof ToCollection,
            $import instanceof WithHeaderRow,
            $import instanceof WithHeaderValidation,
            $import instanceof WithNormalizedHeaders,
            $import instanceof WithMapping,
            $import instanceof WithValidation,
            $import instanceof SkipsOnFailure,
            $import instanceof WithBatchInserts,
            $import instanceof WithChunkReading,
            $import instanceof OnEachRow,
            $import instanceof OnEachChunk,
            $import instanceof WithUpserts,
            $import instanceof WithUpsertColumns,
            $import instanceof SkipsEmptyRows,
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
}
