<?php

declare(strict_types=1);

namespace TurboExcel\Import;

use OpenSpout\Reader\XLSX\Reader;
use TurboExcel\Concerns\WithCsvOptions;
use TurboExcel\Enums\Format;
use TurboExcel\Exceptions\TurboExcelException;
use TurboExcel\Exceptions\UnknownSheetException;
use TurboExcel\Import\Concerns\SkipsEmptyRows;
use TurboExcel\Import\Concerns\SkipsUnknownSheets;
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
        $eol = $this->detectEol($this->path);
        $eolLen = strlen($eol);
        [$delimiter, $enclosure, $escape] = $this->csvOptions();
        $headerRow = $this->import instanceof WithHeaderRow ? $this->import->headerRow() : null;
        $skipsEmpty = $this->import instanceof SkipsEmptyRows;

        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new TurboExcelException("Cannot open CSV: {$this->path}");
        }

        $headerKeys = null;
        $segmentStarts = [];
        $segmentRowNumbers = [];

        $rowNum = 1;
        $dataCount = 0;
        $bufferSize = 1048576; // 1MB

        try {
            CsvReader::skipUtf8BomIfAtStart($handle);
            $globalOffset = (int) ftell($handle);

            // ── PHASE 1: Context-Aware mode ──────────────────────────────────────────
            // Read line-by-line until we are past the header row (and done with
            // SkipsEmptyRows checks). fgets() is safe and correct here because
            // this phase only processes a tiny number of rows (typically 1).
            $needsContextMode = ($headerRow !== null) || $skipsEmpty;
            while ($needsContextMode && ! feof($handle)) {
                $lineStart = $globalOffset;
                $line = fgets($handle);
                if ($line === false) {
                    break;
                }
                $globalOffset += strlen($line);
                $lineContent = rtrim($line, "\r\n");

                $this->fastProcessRow(
                    $lineContent,
                    $rowNum,
                    $headerRow,
                    $skipsEmpty,
                    $delimiter,
                    $enclosure,
                    $escape,
                    $lineStart,
                    $headerKeys,
                    $dataCount,
                    $segmentStarts,
                    $segmentRowNumbers
                );

                $rowNum++;
                $needsContextMode = ($headerRow !== null && $rowNum <= $headerRow) || $skipsEmpty;
            }

            // ── PHASE 2: Turbo Zero-Copy mode ────────────────────────────────────────
            // We are now past the header. We only need to count newlines and record
            // segment boundaries. No string allocations per row.
            $partialRow = false; // tracks whether the last chunk ended mid-row
            $lastSegmentOffset = $globalOffset;

            while (! feof($handle)) {
                $chunk = fread($handle, $bufferSize);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $chunkLen = strlen($chunk);
                $pos = 0;

                while (($eolPos = strpos($chunk, $eol, $pos)) !== false) {
                    // Record a segment start at every chunkSize boundary
                    if ($dataCount % $this->chunkSize === 0) {
                        $segmentStarts[] = $globalOffset + $pos;
                        $segmentRowNumbers[] = $rowNum;
                    }

                    $dataCount++;
                    $rowNum++;
                    $pos = $eolPos + $eolLen;
                }

                $lastSegmentOffset = $globalOffset + $pos;
                $partialRow = ($pos < $chunkLen); // bytes after the last newline
                $globalOffset += $chunkLen;
            }

            // If the file doesn't end with a newline, count the dangling partial row
            if ($partialRow) {
                if ($dataCount % $this->chunkSize === 0) {
                    $segmentStarts[] = $lastSegmentOffset;
                    $segmentRowNumbers[] = $rowNum;
                }
                $dataCount++;
            }

            if ($dataCount === 0) {
                return new ImportScan($headerKeys, [], 0);
            }

            return new ImportScan($headerKeys, $this->buildCsvSegments($segmentStarts, $segmentRowNumbers), $dataCount);
        } finally {
            fclose($handle);
        }
    }

    private function detectEol(string $path): string
    {
        $handle = fopen($path, 'rb');
        $chunk = fread($handle, 8192);
        fclose($handle);

        if ($chunk === false) {
            return "\n";
        }

        if (str_contains($chunk, "\r\n")) {
            return "\r\n";
        }

        if (str_contains($chunk, "\n")) {
            return "\n";
        }

        if (str_contains($chunk, "\r")) {
            return "\r";
        }

        return "\n";
    }

    private function fastProcessRow(
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
            $sheetFound = false;

            foreach ($openSpout->getSheetIterator() as $sheet) {
                if ($sheetIndexTarget !== null) {
                    if ($currentSheetIndex !== $sheetIndexTarget) {
                        $currentSheetIndex++;

                        continue;
                    }
                } elseif ($currentSheetIndex > 0) {
                    break;
                }

                $sheetFound = true;

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

        if ($sheetIndexTarget !== null && ! $sheetFound) {
            if (! $this->import instanceof SkipsUnknownSheets) {
                throw UnknownSheetException::forIndex($sheetIndexTarget);
            }

            return new ImportScan($headerKeys, [], 0);
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
                $sheetFound = false;

                foreach ($openSpout->getSheetIterator() as $sheet) {
                    if ($sheetIndexTarget !== null) {
                        if ($currentSheetIndex !== $sheetIndexTarget) {
                            $currentSheetIndex++;

                            continue;
                        }
                    } elseif ($currentSheetIndex > 0) {
                        break;
                    }

                    $sheetFound = true;

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

            if ($sheetIndexTarget !== null && ! $sheetFound) {
                if (! $this->import instanceof SkipsUnknownSheets) {
                    throw UnknownSheetException::forIndex($sheetIndexTarget);
                }

                return new ImportScan($headerKeys, [], 0);
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
