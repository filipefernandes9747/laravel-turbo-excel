<?php

declare(strict_types=1);

namespace TurboExcel\Import;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use TurboExcel\Concerns\WithAnonymization;
use TurboExcel\Concerns\WithCsvOptions;
use TurboExcel\Enums\Format;
use TurboExcel\Exceptions\TurboExcelException;
use TurboExcel\Import\Concerns\OnEachChunk;
use TurboExcel\Import\Concerns\OnEachRow;
use TurboExcel\Import\Concerns\RemembersRowNumber;
use TurboExcel\Import\Concerns\SkipsEmptyRows;
use TurboExcel\Import\Concerns\SkipsOnFailure;
use TurboExcel\Import\Concerns\ToCollection;
use TurboExcel\Import\Concerns\WithChunkReading;
use TurboExcel\Import\Concerns\WithHeaderRow;
use TurboExcel\Import\Concerns\WithLimit;
use TurboExcel\Import\Concerns\WithMetrics;
use TurboExcel\Import\Concerns\WithProgress;
use TurboExcel\Import\Concerns\WithStartRow;
use TurboExcel\Import\Pipeline\HeaderProcessor;
use TurboExcel\Import\Pipeline\ModelProcessor;
use TurboExcel\Import\Pipeline\RowMapper;
use TurboExcel\Import\Pipeline\RowValidator;
use TurboExcel\Import\Readers\CsvReader;
use TurboExcel\Import\Readers\XlsxReader;
use TurboExcel\Import\Segments\CsvReadSegment;
use TurboExcel\Import\Segments\XlsxReadSegment;

final class SegmentImporter
{
    private const XLSX_MAX_PHYSICAL_ROW = 1_048_576;

    /**
     * @param  list<string>|null  $headerKeys  from scan; null = derive from file when {@see WithHeaderRow}
     * @param  CsvReadSegment|XlsxReadSegment|null  $segment  CSV requires segment; XLSX null = entire first sheet
     */
    public function run(
        object $import,
        string $path,
        Format $format,
        CsvReadSegment|XlsxReadSegment|null $segment,
        ?array $headerKeys,
        ?string $aggregateKey = null,
        int $totalRows = 0,
    ): Result {
        $resolvedKeys = $headerKeys;
        $processed = 0;
        $failed = 0;
        $startTime = microtime(true);

        $metricsEnabled = ($import instanceof WithMetrics) || (method_exists($import, 'isMetricsEnabled') && $import->isMetricsEnabled());

        if ($metricsEnabled) {
            Log::info(sprintf('[TurboExcel] Starting import for %s', basename($path)));
        }

        $csvReader = $this->makeCsvReader($import, $path);
        $xlsxReader = new XlsxReader;
        $models = new ModelProcessor($import);

        /** @var Collection<int, array<string, mixed>>|null $rows */
        $rows = $import instanceof ToCollection ? collect() : null;

        $chunkRows = [];
        $chunkSize = $import instanceof WithChunkReading ? max(1, $import->chunkSize()) : 1000;
        $limit = $import instanceof WithLimit ? $import->limit() : null;
        $startRow = $import instanceof WithStartRow ? $import->startRow() : 1;

        $rowIterator = $this->iterateRows($format, $path, $segment, $csvReader, $xlsxReader);

        foreach ($rowIterator as $item) {
            $cells = $item['cells'];
            $rowIndex = $item['rowIndex'];
            $rowForPipeline = null;

            if ($rowIndex < $startRow) {
                continue;
            }

            if ($limit !== null && $processed >= $limit) {
                break;
            }

            if ($import instanceof RemembersRowNumber) {
                $import->setRowNumber($rowIndex);
            }

            if ($import instanceof SkipsEmptyRows && $this->isEmptyRow($cells)) {
                continue;
            }

            try {
                if ($import instanceof WithHeaderRow && $rowIndex === max(1, $import->headerRow())) {
                    if ($resolvedKeys === null) {
                        HeaderProcessor::validateHeaders($cells, $import);
                        $resolvedKeys = HeaderProcessor::buildHeaderKeys($cells, $import);
                    }

                    continue;
                }

                $rowForPipeline = $this->combineRow($resolvedKeys, $cells, $import);

                $mapped = RowMapper::map($import, $rowForPipeline);
                $data = $this->normalizeKeysForLaravel($mapped);

                if ($import instanceof WithAnonymization) {
                    $data = $this->anonymizeRow($data, $import);
                }

                RowValidator::validate($import, $data);
                $models->persist($data);
                if ($rows !== null) {
                    $rows->push($data);
                }

                if ($import instanceof OnEachRow) {
                    $import->onRow($data);
                }

                if ($import instanceof OnEachChunk) {
                    $chunkRows[] = $data;
                    if (count($chunkRows) >= $chunkSize) {
                        $import->onChunk(collect($chunkRows));
                        $chunkRows = [];
                    }
                }

                $processed++;

                if ($metricsEnabled && ($processed % $chunkSize === 0)) {
                    Log::info(sprintf(
                        '[TurboExcel] Processed %d rows... (Memory: %.2f MB)',
                        $processed,
                        memory_get_peak_usage(true) / 1024 / 1024
                    ));
                }
            } catch (\Throwable $e) {
                if ($import instanceof SkipsOnFailure) {
                    $import->onFailure($rowForPipeline ?? $cells, $e);
                    $failed++;
                } else {
                    $models->flush();
                    throw $e;
                }
            }
        }

        $models->flush();

        if ($import instanceof OnEachChunk && count($chunkRows) > 0) {
            $import->onChunk(collect($chunkRows));
        }

        $duration = microtime(true) - $startTime;
        $peakMemory = memory_get_peak_usage(true) / 1024 / 1024;

        $result = new Result($processed, $failed, $rows, $duration, $peakMemory);

        if ($aggregateKey !== null) {
            Cache::increment("turbo_excel_import:{$aggregateKey}:processed", $result->processed);
            Cache::increment("turbo_excel_import:{$aggregateKey}:failed", $result->failed);

            if ($import instanceof WithProgress && $totalRows > 0) {
                $globalProcessed = (int) Cache::get("turbo_excel_import:{$aggregateKey}:processed", 0);
                $percentage = (int) min(100, round(($globalProcessed / $totalRows) * 100));
                Cache::put($import->progressKey(), $percentage, 3600);
            }
        } elseif ($import instanceof WithProgress && $totalRows > 0) {
            $percentage = (int) min(100, round(($processed / $totalRows) * 100));
            Cache::put($import->progressKey(), $percentage, 3600);
        }

        if ($metricsEnabled) {
            Log::info(sprintf(
                '[TurboExcel] Import finished. Processed: %d, Failed: %d, Time: %.2fs, Peak Memory: %.2f MB',
                $processed,
                $failed,
                microtime(true) - $startTime,
                memory_get_peak_usage(true) / 1024 / 1024
            ));
        }

        return $result;
    }

    /**
     * @param  list<string>|null  $headerKeys
     * @return array<int|string, mixed>
     */
    private function combineRow(?array $headerKeys, array $cells, object $import): array
    {
        if ($headerKeys === null) {
            /** @var array<int|string, mixed> */
            return $cells;
        }

        if (count($headerKeys) !== count($cells)) {
            throw new TurboExcelException(
                'Column count does not match header count ('.count($headerKeys).' vs '.count($cells).').',
            );
        }

        /** @var array<string, string> $combined */
        $combined = array_combine($headerKeys, $cells);

        return $combined;
    }

    /**
     * @param  array<int|string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeKeysForLaravel(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[is_string($k) ? $k : (string) $k] = $v;
        }

        return $out;
    }

    /**
     * @return \Generator<int, array{rowIndex: int, cells: list<string>}>
     */
    private function iterateRows(
        Format $format,
        string $path,
        CsvReadSegment|XlsxReadSegment|null $segment,
        CsvReader $csvReader,
        XlsxReader $xlsxReader,
    ): \Generator {
        if ($format === Format::CSV) {
            $seg = $segment instanceof CsvReadSegment
                ? $segment
                : new CsvReadSegment(0, null);

            yield from $csvReader->iterateByByteOffset($seg->startByte, $seg->endByte, $seg->startRowIndex);

            return;
        }

        $xlsxSeg = $segment instanceof XlsxReadSegment
            ? $segment
            : new XlsxReadSegment(1, self::XLSX_MAX_PHYSICAL_ROW, 0);

        yield from $xlsxReader->rows($path, $xlsxSeg);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function anonymizeRow(array $row, WithAnonymization $import): array
    {
        $columns = $import->anonymizeColumns();
        $replacement = $import->anonymizeReplacement();

        foreach ($columns as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = $replacement;
            }
        }

        return $row;
    }

    private function makeCsvReader(object $import, string $path): CsvReader
    {
        if ($import instanceof WithCsvOptions) {
            return new CsvReader(
                $path,
                $import->delimiter(),
                $import->enclosure(),
                '\\',
            );
        }

        return new CsvReader($path);
    }

    /**
     * @param  list<string>  $cells
     */
    private function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== '') {
                return false;
            }
        }

        return true;
    }
}
