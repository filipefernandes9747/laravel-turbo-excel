<?php

declare(strict_types=1);

namespace TurboExcel\Import;

use OpenSpout\Reader\XLSX\Reader;
use TurboExcel\Concerns\WithCsvOptions;
use TurboExcel\Enums\Format;
use TurboExcel\Exceptions\TurboExcelException;
use TurboExcel\Import\Concerns\WithHeaderRow;
use TurboExcel\Import\Pipeline\HeaderProcessor;
use TurboExcel\Import\Readers\CsvReader;
use TurboExcel\Import\Readers\XlsxReader;
use TurboExcel\Import\Scanners\XlsxQuickScanner;
use TurboExcel\Import\Segments\CsvReadSegment;
use TurboExcel\Import\Segments\XlsxReadSegment;

final class ImportScanner
{
    public function __construct(
        private readonly object $import,
        private readonly string $path,
        private readonly Format $format,
        private readonly int $chunkSize,
        /**
         * 0-based worksheet index for XLSX scans. `null` = scan only the first worksheet (default).
         */
        private readonly ?int $xlsxSheetIndex = null,
    ) {
        if ($this->chunkSize < 1) {
            throw new TurboExcelException('chunkSize must be at least 1.');
        }
    }

    public function scan(): ImportScan
    {
        return match ($this->format) {
            Format::CSV => $this->scanCsv(),
            Format::XLSX => $this->scanXlsx(),
        };
    }

    private function scanCsv(): ImportScan
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new TurboExcelException("Cannot open CSV: {$this->path}");
        }

        try {
            CsvReader::skipUtf8BomIfAtStart($handle);
            [$delimiter, $enclosure, $escape] = $this->csvOptions();

            $headerRow = $this->import instanceof WithHeaderRow ? max(1, $this->import->headerRow()) : null;
            $skipsEmpty = $this->import instanceof \TurboExcel\Import\Concerns\SkipsEmptyRows;
            $rowNum = 1;
            $dataCount = 0;
            $headerKeys = null;

            /** @var list<int> $segmentStarts */
            $segmentStarts = [];
            /** @var list<int> $segmentRowNumbers */
            $segmentRowNumbers = [];

            $inside = false;
            $currentLineStart = ftell($handle);
            $currentRecordBuffer = '';

            while (($line = fgets($handle)) !== false) {
                $posAfter = ftell($handle);
                $currentRecordBuffer .= $line;

                // Fast count of enclosures to handle multi-line CSV records
                $quoteCount = substr_count($line, $enclosure);
                
                // If escape is the same as enclosure (standard ""), 
                // we don't need complex logic, just parity.
                if ($quoteCount % 2 !== 0) {
                    $inside = ! $inside;
                }

                if (! $inside) {
                    // We found a complete row boundary!
                    $this->processScannedRow(
                        $currentRecordBuffer,
                        $rowNum,
                        $headerRow,
                        $skipsEmpty,
                        $delimiter,
                        $enclosure,
                        $escape,
                        $currentLineStart,
                        $headerKeys,
                        $dataCount,
                        $segmentStarts,
                        $segmentRowNumbers
                    );

                    $rowNum++;
                    $currentRecordBuffer = '';
                    $currentLineStart = $posAfter;
                }
            }

            // Handle potential last row without a newline
            if ($currentRecordBuffer !== '') {
                $this->processScannedRow(
                    $currentRecordBuffer,
                    $rowNum,
                    $headerRow,
                    $skipsEmpty,
                    $delimiter,
                    $enclosure,
                    $escape,
                    $currentLineStart,
                    $headerKeys,
                    $dataCount,
                    $segmentStarts,
                    $segmentRowNumbers
                );
            }

            if ($dataCount === 0) {
                return new ImportScan($headerKeys, [], 0);
            }

            $segments = $this->buildCsvSegments($segmentStarts, $segmentRowNumbers);

            return new ImportScan($headerKeys, $segments, $dataCount);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<int>  $segmentStarts
     * @param  list<int>  $segmentRowNumbers
     */
    private function processScannedRow(
        string $lineContent,
        int $rowNum,
        ?int $headerRow,
        bool $skipsEmpty,
        string $delimiter,
        string $enclosure,
        string $escape,
        int $currentLineStart,
        ?array &$headerKeys,
        int &$dataCount,
        array &$segmentStarts,
        array &$segmentRowNumbers,
    ): void {
        if ($headerRow !== null && $rowNum === $headerRow) {
            $cells = str_getcsv($lineContent, $delimiter, $enclosure, $escape);
            $normalized = $this->normalizeCsvLine($cells);
            HeaderProcessor::validateHeaders($normalized, $this->import);
            $headerKeys = HeaderProcessor::buildHeaderKeys($normalized, $this->import);

            return;
        }

        if ($headerRow !== null && $rowNum < $headerRow) {
            return;
        }

        if ($skipsEmpty && $this->isByteLineEmpty($lineContent, $delimiter)) {
            return;
        }

        if ($dataCount % $this->chunkSize === 0) {
            $segmentStarts[] = $currentLineStart;
            $segmentRowNumbers[] = $rowNum;
        }

        $dataCount++;
    }

    private function isByteLineEmpty(string $line, string $delimiter): bool
    {
        $line = trim($line);
        if ($line === '') {
            return true;
        }

        // Fast check: if it's strictly delimiters/spaces
        $cleaned = str_replace([$delimiter, ' ', "\t"], '', $line);

        return $cleaned === '';
    }

    /**
     * @param  list<int>  $starts
     * @param  list<int>  $rowNumbers
     * @return list<CsvReadSegment>
     */
    private function buildCsvSegments(array $starts, array $rowNumbers): array
    {
        $segments = [];
        $n = count($starts);
        for ($i = 0; $i < $n; $i++) {
            $start = $starts[$i];
            $end = isset($starts[$i + 1]) ? $starts[$i + 1] : null;
            $segments[] = new CsvReadSegment($start, $end, $rowNumbers[$i]);
        }

        return $segments;
    }

    private function scanXlsx(): ImportScan
    {
        $headerRow = $this->import instanceof WithHeaderRow ? max(1, $this->import->headerRow()) : null;
        $headerKeys = null;

        /** @var list<int> $segmentStarts */
        $segmentStarts = [];
        $dataCount = 0;
        $lastDataRow = null;

        $quickScanner = new XlsxQuickScanner;
        $quickRowCount = $quickScanner->getRowCount($this->path, $this->xlsxSheetIndex ?? 0);

        if ($quickRowCount !== null) {
            return $this->fastScanXlsx($quickRowCount, $headerRow);
        }

        $openSpout = new Reader;
        $openSpout->open($this->path);

        try {
            $sheetIndexTarget = $this->xlsxSheetIndex;
            $currentSheetIndex = 0;

            foreach ($openSpout->getSheetIterator() as $sheet) {
                if ($sheetIndexTarget !== null) {
                    if ($currentSheetIndex !== $sheetIndexTarget) {
                        $currentSheetIndex++;

                        continue;
                    }
                } elseif ($currentSheetIndex > 0) {
                    break;
                }

                $rowIndex = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $rowIndex++;

                    if ($headerRow !== null && $rowIndex === $headerRow) {
                        $cells = XlsxReader::indexedCells($row);
                        HeaderProcessor::validateHeaders($cells, $this->import);
                        $headerKeys = HeaderProcessor::buildHeaderKeys($cells, $this->import);

                        continue;
                    }

                    if ($headerRow !== null && $rowIndex < $headerRow) {
                        continue;
                    }

                    if ($dataCount % $this->chunkSize === 0) {
                        $segmentStarts[] = $rowIndex;
                    }

                    $dataCount++;
                    $lastDataRow = $rowIndex;
                }

                break;
            }
        } finally {
            $openSpout->close();
        }

        if ($dataCount === 0) {
            return new ImportScan($headerKeys, [], 0);
        }

        $segments = $this->buildXlsxSegments(
            $segmentStarts,
            $lastDataRow,
            $this->xlsxSheetIndex ?? 0,
        );

        return new ImportScan($headerKeys, $segments, $dataCount);
    }

    /**
     * @param  list<int>  $starts
     * @return list<XlsxReadSegment>
     */
    private function buildXlsxSegments(array $starts, ?int $lastDataRow, int $sheetIndex): array
    {
        if ($lastDataRow === null) {
            return [];
        }

        $segments = [];
        $n = count($starts);
        for ($i = 0; $i < $n; $i++) {
            $start = $starts[$i];
            $end = isset($starts[$i + 1]) ? $starts[$i + 1] - 1 : $lastDataRow;
            $segments[] = new XlsxReadSegment($start, $end, $sheetIndex);
        }

        return $segments;
    }

    private function fastScanXlsx(int $totalRows, ?int $headerRow): ImportScan
    {
        $headerKeys = null;

        if ($headerRow !== null) {
            $openSpout = new Reader;
            $openSpout->open($this->path);

            try {
                $sheetIndexTarget = $this->xlsxSheetIndex;
                $currentSheetIndex = 0;

                foreach ($openSpout->getSheetIterator() as $sheet) {
                    if ($sheetIndexTarget !== null) {
                        if ($currentSheetIndex !== $sheetIndexTarget) {
                            $currentSheetIndex++;

                            continue;
                        }
                    } elseif ($currentSheetIndex > 0) {
                        break;
                    }

                    $rowIndex = 0;
                    foreach ($sheet->getRowIterator() as $row) {
                        if (++$rowIndex === $headerRow) {
                            $cells = XlsxReader::indexedCells($row);
                            HeaderProcessor::validateHeaders($cells, $this->import);
                            $headerKeys = HeaderProcessor::buildHeaderKeys($cells, $this->import);

                            break;
                        }
                    }

                    break;
                }
            } finally {
                $openSpout->close();
            }
        }

        $firstDataRow = $headerRow !== null ? $headerRow + 1 : 1;
        $dataCount = max(0, $totalRows - $firstDataRow + 1);

        if ($dataCount === 0) {
            return new ImportScan($headerKeys, [], 0);
        }

        $segmentStarts = [];
        for ($i = 0; $i < $dataCount; $i += $this->chunkSize) {
            $segmentStarts[] = $firstDataRow + $i;
        }

        $segments = $this->buildXlsxSegments(
            $segmentStarts,
            $totalRows,
            $this->xlsxSheetIndex ?? 0,
        );

        return new ImportScan($headerKeys, $segments, $dataCount);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function csvOptions(): array
    {
        if ($this->import instanceof WithCsvOptions) {
            return [
                $this->import->delimiter(),
                $this->import->enclosure(),
                '\\',
            ];
        }

        return [',', '"', '\\'];
    }

    /**
     * @param  array<int, string|null>  $line
     */
    private function isCsvRecordEmpty(array $line): bool
    {
        if ($line === []) {
            return true;
        }

        foreach ($line as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string|null>  $line
     * @return list<string>
     */
    private function normalizeCsvLine(array $line): array
    {
        $cells = [];
        foreach ($line as $cell) {
            $cells[] = $cell === null ? '' : (string) $cell;
        }

        return array_values($cells);
    }
}
