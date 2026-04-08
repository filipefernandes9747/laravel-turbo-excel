<?php

declare(strict_types=1);

namespace TurboExcel;

use TurboExcel\Concerns\FromArray;
use TurboExcel\Concerns\FromCollection;
use TurboExcel\Concerns\FromGenerator;
use TurboExcel\Concerns\FromQuery;
use TurboExcel\Concerns\WithChunkSize;
use TurboExcel\Concerns\WithColumnFormatting;
use TurboExcel\Concerns\WithHeadings;
use TurboExcel\Concerns\WithMapping;
use TurboExcel\Concerns\WithAnonymization;
use TurboExcel\Concerns\WithMultipleSheets;
use TurboExcel\Concerns\WithQuerySplitBySheet;
use TurboExcel\Concerns\WithStyles;
use TurboExcel\Concerns\WithTitle;
use TurboExcel\Enums\Format;
use TurboExcel\Exceptions\TurboExcelException;
use TurboExcel\Writers\Contracts\WriterInterface;
use TurboExcel\Writers\CsvWriter;
use TurboExcel\Writers\XlsxWriter;
use Illuminate\Database\Eloquent\Model;
use OpenSpout\Common\Entity\Style\Style;

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
        $isDebug = $this->export instanceof \TurboExcel\Concerns\WithDebug;
        $startTime = microtime(true);

        if ($isDebug) {
            \Illuminate\Support\Facades\Log::info('TurboExcel: Starting export', [
                'format' => $this->format->value,
                'path'   => $path,
                'class'  => $this->export::class,
            ]);
        }

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

        if ($isDebug) {
            \Illuminate\Support\Facades\Log::info('TurboExcel: Export completed', [
                'total_rows'     => $totalRows,
                'execution_time' => round(microtime(true) - $startTime, 2) . 's',
                'peak_memory'    => round(memory_get_peak_usage() / 1024 / 1024, 2) . 'MB',
            ]);
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
        $rowsWritten       = 0;
        $isFirstSheet      = true;
        $headingsWritten   = false;

        $chunkSize = $export instanceof WithChunkSize ? $export->chunkSize() : 1000;
        $splitCol  = $export->splitByColumn();

        // The active handler for the current sheet segment.
        $handler            = $export;
        $columnStyles       = [];
        $headerStyle        = null;
        $anonymizeColumns   = [];
        $anonymizeReplacement = '';

        foreach ($this->resolveRows($export, $chunkSize) as $rawRow) {
            $item = $this->normaliseRow($rawRow);
            $splitValue = $item[$splitCol] ?? null;

            if ($isFirstSheet || $splitValue !== $currentSplitValue) {
                // Determine the new sheet handler
                $handler = $export->sheet($rawRow);
                
                $title = $handler instanceof WithTitle
                    ? $handler->title()
                    : 'Sheet ' . ($splitValue ?? '1');

                $writer->addSheet($title, $isFirstSheet);
                
                $isFirstSheet    = false;
                $headingsWritten = false;
                $currentSplitValue = $splitValue;

                // Refresh sheet-level concerns from the new handler
                $columnStyles = $this->resolveColumnStyles($handler);
                $headerStyle  = $columnStyles['header'] ?? null;

                $shouldAnonymize      = $handler instanceof WithAnonymization && (!method_exists($handler, 'isAnonymizationEnabled') || $handler->isAnonymizationEnabled());
                $anonymizeColumns     = $shouldAnonymize ? $handler->anonymizeColumns() : [];
                $anonymizeReplacement = $handler instanceof WithAnonymization ? $handler->anonymizeReplacement() : '';

                if ($isDebug) {
                    \Illuminate\Support\Facades\Log::debug("TurboExcel: Split detected, starting sheet '{$title}' with handler [" . $handler::class . "]");
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

                $writer->writeRow(\OpenSpout\Common\Entity\Row::fromValues($headings, $headerStyle));
                $headingsWritten = true;
            }

            $writer->writeRow(\OpenSpout\Common\Entity\Row::fromValuesWithStyles(array_values($row), null, $columnStyles));
            $rowsWritten++;

            if ($this->onProgress) {
                ($this->onProgress)();
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
        $headerStyle  = $columnStyles['header'] ?? null;

        // --- Anonymization ---
        $shouldAnonymize      = $export instanceof WithAnonymization && (!method_exists($export, 'isAnonymizationEnabled') || $export->isAnonymizationEnabled());
        $anonymizeColumns     = $shouldAnonymize ? $export->anonymizeColumns() : [];
        $anonymizeReplacement = $export instanceof WithAnonymization ? $export->anonymizeReplacement() : '';

        // --- Stream rows ---
        $headingsWritten = false;
        $rowsWritten = 0;

        foreach ($this->resolveRows($export, $chunkSize) as $rawRow) {
            $row = $export instanceof WithMapping
                ? $export->map($rawRow)
                : $this->normaliseRow($rawRow);

            if ($anonymizeColumns) {
                foreach ($anonymizeColumns as $col) {
                    if (array_key_exists($col, $row)) {
                        $row[$col] = $anonymizeReplacement;
                    }
                }
            }

            // Write the heading row once, before the first data row.
            if (! $headingsWritten) {
                $headings = $export instanceof WithHeadings
                    ? $export->headings()
                    : array_keys($row);

                $writer->writeRow(\OpenSpout\Common\Entity\Row::fromValues($headings, $headerStyle));
                $headingsWritten = true;
            }

            $writer->writeRow(\OpenSpout\Common\Entity\Row::fromValuesWithStyles(array_values($row), null, $columnStyles));
            $rowsWritten++;
            
            if ($isDebug && $rowsWritten % 5000 === 0) {
                \Illuminate\Support\Facades\Log::debug("TurboExcel: Processed {$rowsWritten} rows on sheet '{$title}'", [
                    'peak_memory' => round(memory_get_peak_usage() / 1024 / 1024, 2) . 'MB',
                ]);
            }
            
            if ($this->onProgress) {
                ($this->onProgress)();
            }
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
            $export instanceof FromCollection        => $export->collection(),
            $export instanceof FromArray             => $export->array(),
            $export instanceof FromGenerator         => $export->generator(),
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
    private function normaliseRow(mixed $item): array
    {
        return match (true) {
            $item instanceof Model             => $item->toArray(),
            $item instanceof \JsonSerializable => (array) $item->jsonSerialize(),
            is_array($item)                    => $item,
            is_object($item)                   => (array) $item,
            default                            => [$item],
        };
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
                $styles[$index] = (new Style())->setFormat($formatString);
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
        $index  = 0;

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
            Format::XLSX => new XlsxWriter(),
            Format::CSV  => new CsvWriter(),
        };
    }
}
