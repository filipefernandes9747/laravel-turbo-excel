<?php

declare(strict_types=1);

namespace TurboExcel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use TurboExcel\Concerns\FromArray;
use TurboExcel\Concerns\FromCollection;
use TurboExcel\Concerns\FromGenerator;
use TurboExcel\Concerns\FromQuery;
use TurboExcel\Concerns\WithAnonymization;
use TurboExcel\Concerns\WithChunkSize;
use TurboExcel\Concerns\WithColumnFormatting;
use TurboExcel\Concerns\WithDebug;
use TurboExcel\Concerns\WithErrorHandling;
use TurboExcel\Concerns\WithEvents;
use TurboExcel\Concerns\WithHeadings;
use TurboExcel\Concerns\WithLimit;
use TurboExcel\Concerns\WithMapping;
use TurboExcel\Concerns\WithMultipleSheets;
use TurboExcel\Concerns\WithQuerySplitBySheet;
use TurboExcel\Concerns\WithStrictNullComparison;
use TurboExcel\Concerns\WithStyles;
use TurboExcel\Concerns\WithTitle;
use TurboExcel\Concerns\WithTranslation;
use TurboExcel\Enums\Format;
use TurboExcel\Events\AfterExport;
use TurboExcel\Events\BeforeExport;
use TurboExcel\Exceptions\TurboExcelException;
use TurboExcel\Writers\Contracts\WriterInterface;
use TurboExcel\Writers\CsvWriter;
use TurboExcel\Writers\XlsxWriter;

/**
 * Orchestrates concern-aware export pipeline.
 *
 * Resolution order for data sources (highest → lowest priority):
 *   FromQuery → FromCollection → FromArray → FromGenerator
 */
final class Exporter
{
    private ?\Closure $onProgress = null;

    public function __construct(
        private readonly object $export,
        private readonly Format $format,
    ) {}

    public function onProgress(\Closure $callback): self
    {
        $this->onProgress = $callback;

        return $this;
    }

    // ---------------------------------------------------------------------------
    // Public
    // ---------------------------------------------------------------------------

    public function export(string $path): void
    {
        $isDebug = $this->export instanceof WithDebug;
        $startTime = microtime(true);

        if ($this->export instanceof WithEvents) {
            if (isset($this->export->registerEvents()[BeforeExport::class])) {
                $this->export->registerEvents()[BeforeExport::class](new BeforeExport($this, $this->export));
            }
        }

        if ($isDebug) {
            Log::info('TurboExcel: Starting export', [
                'format' => $this->format->value,
                'path' => $path,
                'class' => $this->export::class,
            ]);
        }

        try {
            $writer = $this->resolveWriter();
            $writer->applyOptions($this->export);
            $writer->open($path);

            $totalRows = 0;
            if ($this->export instanceof WithQuerySplitBySheet) {
                $totalRows = $this->writeSplitSheet($this->export, $writer, $isDebug);
            } elseif ($this->export instanceof WithMultipleSheets) {
                $totalRows = $this->writeMultipleSheets($this->export->sheets(), $writer, $isDebug);
            } else {
                $totalRows = $this->writeSheet($this->export, $writer, true, $isDebug);
            }

            $writer->close();
        } catch (\Throwable $e) {
            if ($this->export instanceof WithErrorHandling) {
                $this->export->handleError($e);

                return;
            }

            throw $e;
        }

        if ($isDebug) {
            Log::info('TurboExcel: Export completed', [
                'total_rows' => $totalRows,
                'execution_time' => round(microtime(true) - $startTime, 2).'s',
                'peak_memory' => round(memory_get_peak_usage() / 1024 / 1024, 2).'MB',
            ]);
        }

        if ($this->export instanceof WithEvents) {
            if (isset($this->export->registerEvents()[AfterExport::class])) {
                $this->export->registerEvents()[AfterExport::class](new AfterExport($this, $this->export));
            }
        }
    }


    // ---------------------------------------------------------------------------
    // Sheet writing
    // ---------------------------------------------------------------------------

    /**
     * @param  object[]  $sheets
     */
    private function writeMultipleSheets(array $sheets, WriterInterface $writer, bool $isDebug): int
    {
        if ($this->format === Format::CSV) {
            throw new TurboExcelException(
                'CSV does not support multiple sheets. Use Format::XLSX for multi-sheet exports.',
            );
        }

        $totalRows = 0;
        foreach ($sheets as $index => $sheetExport) {
            $totalRows += $this->writeSheet($sheetExport, $writer, $index === 0, $isDebug);
        }

        return $totalRows;
    }

    private function writeSplitSheet(WithQuerySplitBySheet $export, WriterInterface $writer, bool $isDebug): int
    {
        if ($this->format === Format::CSV) {
            throw new TurboExcelException(
                'WithQuerySplitBySheet is only supported for XLSX exports.',
            );
        }

        $currentSplitValue = null;
        $rowsWritten = 0;
        $isFirstSheet = true;
        $headingsWritten = false;

        $chunkSize = $export instanceof WithChunkSize ? $export->chunkSize() : 1000;
        $splitCol = $export->splitByColumn();
        $limit = $export instanceof WithLimit ? $export->limit() : null;

        // The active handler for the current sheet segment.
        $handler = $export;
        $columnStyles = [];
        $headerStyle = null;
        $anonymizeColumns = [];
        $anonymizeReplacement = '';

        foreach ($this->resolveRows($export, $chunkSize) as $rawRow) {
            if ($limit !== null && $rowsWritten >= $limit) {
                break;
            }

            $item = $this->normaliseRow($rawRow, $handler);
            $splitValue = $item[$splitCol] ?? null;

            if ($isFirstSheet || $splitValue !== $currentSplitValue) {
                // Determine the new sheet handler
                $handler = $export->sheet($rawRow);

                $title = $handler instanceof WithTitle
                    ? $handler->title()
                    : 'Sheet '.($splitValue ?? '1');

                $writer->addSheet($title, $isFirstSheet);

                $isFirstSheet = false;
                $headingsWritten = false;
                $currentSplitValue = $splitValue;

                // Refresh sheet-level concerns from the new handler
                $columnStyles = $this->resolveColumnStyles($handler);
                $headerStyle = $columnStyles['header'] ?? null;

                $shouldAnonymize = $handler instanceof WithAnonymization && (! method_exists($handler, 'isAnonymizationEnabled') || $handler->isAnonymizationEnabled());
                $anonymizeColumns = $shouldAnonymize ? $handler->anonymizeColumns() : [];
                $anonymizeReplacement = $handler instanceof WithAnonymization ? $handler->anonymizeReplacement() : '';

                if ($isDebug) {
                    Log::debug("TurboExcel: Split detected, starting sheet '{$title}' with handler [".$handler::class.']');
                }
            }

            $row = $handler instanceof WithMapping
                ? $handler->map($rawRow)
                : $item;

            if ($anonymizeColumns) {
                foreach ($anonymizeColumns as $col) {
                    if (array_key_exists($col, $row)) {
                        $row[$col] = $anonymizeReplacement;
                    }
                }
            }

            if (! $headingsWritten) {
                $headings = $handler instanceof WithHeadings
                    ? $handler->headings()
                    : array_keys($row);

                if ($handler instanceof WithTranslation) {
                    $headings = array_map(fn ($h) => trans($h), $headings);
                }

                $writer->writeRow(Row::fromValues($headings, $headerStyle));
                $headingsWritten = true;
            }

            $writer->writeRow(Row::fromValuesWithStyles(array_values($row), null, $columnStyles));
            $rowsWritten++;

            if ($this->onProgress) {
                ($this->onProgress)();
            }
        }


        // If no rows were written at all, ensure at least one sheet exists
        // (renaming the default one) and write headings if available.
        if ($rowsWritten === 0) {
            $title = $export instanceof WithTitle ? $export->title() : 'Sheet 1';
            $writer->addSheet($title, true);

            if ($export instanceof WithHeadings) {
                $columnStyles = $this->resolveColumnStyles($export);
                $headerStyle = $columnStyles['header'] ?? null;
                $writer->writeRow(Row::fromValues($export->headings(), $headerStyle));
            } else {
                // Ensure at least one row exists to avoid Excel corruption.
                $writer->writeRow(Row::fromValues([' ']));
            }
        }

        return $rowsWritten;
    }

    private function writeSheet(object $export, WriterInterface $writer, bool $first, bool $isDebug): int
    {
        // --- Sheet name ---
        $title = $export instanceof WithTitle
            ? $export->title()
            : 'Sheet 1';

        $writer->addSheet($title, $first);

        // --- Chunk size (only relevant for FromQuery) ---
        $chunkSize = $export instanceof WithChunkSize
            ? $export->chunkSize()
            : 1000;

        // --- Styles ---
        $columnStyles = $this->resolveColumnStyles($export);
        $headerStyle = $columnStyles['header'] ?? null;

        // --- Anonymization ---
        $shouldAnonymize = $export instanceof WithAnonymization && (! method_exists($export, 'isAnonymizationEnabled') || $export->isAnonymizationEnabled());
        $anonymizeColumns = $shouldAnonymize ? $export->anonymizeColumns() : [];
        $anonymizeReplacement = $export instanceof WithAnonymization ? $export->anonymizeReplacement() : '';

        $limit = $export instanceof WithLimit ? $export->limit() : null;

        // --- Stream rows ---
        $headingsWritten = false;
        $rowsWritten = 0;

        // If headings are explicitly provided, write them now so empty exports
        // still produce a valid Excel file with at least a header row.
        if ($export instanceof WithHeadings) {
            $headings = $export->headings();
            if ($export instanceof WithTranslation) {
                $headings = array_map(fn ($h) => trans($h), $headings);
            }
            $writer->writeRow(Row::fromValues($headings, $headerStyle));
            $headingsWritten = true;
        }

        foreach ($this->resolveRows($export, $chunkSize) as $rawRow) {
            if ($limit !== null && $rowsWritten >= $limit) {
                break;
            }

            $row = $export instanceof WithMapping
                ? $export->map($rawRow)
                : $this->normaliseRow($rawRow, $export);

            if ($anonymizeColumns) {
                foreach ($anonymizeColumns as $col) {
                    if (array_key_exists($col, $row)) {
                        $row[$col] = $anonymizeReplacement;
                    }
                }
            }

            // Write the heading row once, before the first data row.
            // (Only if not already written above)
            if (! $headingsWritten) {
                $headings = $export instanceof WithHeadings
                    ? $export->headings()
                    : array_keys($row);

                if ($export instanceof WithTranslation) {
                    $headings = array_map(fn ($h) => trans($h), $headings);
                }

                $writer->writeRow(Row::fromValues($headings, $headerStyle));
                $headingsWritten = true;
            }

            $writer->writeRow(Row::fromValuesWithStyles(array_values($row), null, $columnStyles));
            $rowsWritten++;

            if ($isDebug && $rowsWritten % 5000 === 0) {
                Log::debug("TurboExcel: Processed {$rowsWritten} rows on sheet '{$title}'", [
                    'peak_memory' => round(memory_get_peak_usage() / 1024 / 1024, 2).'MB',
                ]);
            }

            if ($this->onProgress) {
                ($this->onProgress)();
            }
        }

        if (! $headingsWritten && $rowsWritten === 0) {
            // Ensure at least one row exists to avoid Excel corruption.
            $writer->writeRow(Row::fromValues([' ']));
        }

        return $rowsWritten;
    }


    // ---------------------------------------------------------------------------
    // Data-source resolution
    // ---------------------------------------------------------------------------

    /**
     * Resolve the iterable data source from the export's concern(s).
     *
     * @return iterable<mixed>
     *
     * @throws TurboExcelException When no data-source concern is implemented.
     */
    private function resolveRows(object $export, int $chunkSize): iterable
    {
        return match (true) {
            $export instanceof FromQuery,
            $export instanceof WithQuerySplitBySheet => $export->query()->lazy($chunkSize),
            $export instanceof FromCollection => $export->collection(),
            $export instanceof FromArray => $export->array(),
            $export instanceof FromGenerator => $export->generator(),
            default => throw new TurboExcelException(
                sprintf(
                    'Export class [%s] must implement one of: FromQuery, FromCollection, FromArray, or FromGenerator.',
                    $export::class,
                ),
            ),
        };
    }

    // ---------------------------------------------------------------------------
    // Row normalisation
    // ---------------------------------------------------------------------------

    /**
     * Convert any raw item to a flat associative array.
     *
     * @return array<string, mixed>
     */
    private function normaliseRow(mixed $item, ?object $export = null): array
    {
        $data = match (true) {
            $item instanceof Model => $item->toArray(),
            $item instanceof \JsonSerializable => (array) $item->jsonSerialize(),
            is_array($item) => $item,
            is_object($item) => (array) $item,
            default => [$item],
        };

        if ($export instanceof WithStrictNullComparison) {
            // Strict null usually means "don't convert null to empty string".
            // OpenSpout handles null correctly if we pass it correctly.
            // We just ensure we return the data without any loose conversions.
        }

        return $data;
    }


    // ---------------------------------------------------------------------------
    // Formatting & Styling resolution
    // ---------------------------------------------------------------------------

    /**
     * @return array<int|string, Style>
     */
    private function resolveColumnStyles(object $export): array
    {
        if ($this->format === Format::CSV) {
            return [];
        }

        $styles = [];

        // 1. Resolve from WithColumnFormatting
        if ($export instanceof WithColumnFormatting) {
            foreach ($export->columnFormats() as $column => $formatString) {
                $index = $this->columnIndex($column);
                $styles[$index] = (new Style)->setFormat($formatString);
            }
        }

        // 2. Resolve from WithStyles (merging/overwriting)
        if ($export instanceof WithStyles) {
            foreach ($export->styles() as $column => $style) {
                if ($column === 'header') {
                    $styles['header'] = $style;

                    continue;
                }
                $index = $this->columnIndex($column);
                $styles[$index] = $style;
            }
        }

        return $styles;
    }

    /**
     * Convert Excel column letter (A, B...) to 0-based index.
     */
    private function columnIndex(int|string $column): int
    {
        if (is_int($column)) {
            return $column;
        }

        $column = strtoupper($column);
        $length = strlen($column);
        $index = 0;

        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + ord($column[$i]) - ord('A') + 1;
        }

        return $index - 1;
    }

    // ---------------------------------------------------------------------------
    // Writer resolution
    // ---------------------------------------------------------------------------

    private function resolveWriter(): WriterInterface
    {
        return match ($this->format) {
            Format::XLSX => new XlsxWriter,
            Format::CSV => new CsvWriter,
        };
    }
}
