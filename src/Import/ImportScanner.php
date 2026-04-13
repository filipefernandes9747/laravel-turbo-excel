<?php

declare(strict_types=1);

namespace TurboExcel\Import;

use OpenSpout\Reader\XLSX\Reader;
use TurboExcel\Concerns\WithCsvOptions;
use TurboExcel\Enums\Format;
use TurboExcel\Exceptions\TurboExcelException;
use TurboExcel\Import\Concerns\SkipsEmptyRows;
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
            $skipsEmpty = $this->import instanceof SkipsEmptyRows;
            $rowNum = 1;
            $dataCount = 0;
            $headerKeys = null;
            $segmentStarts = [];
            $segmentRowNumbers = [];

            $inside = false;
            $currentLineStart = ftell($handle);
            $currentRecordBuffer = '';
            $dataCount = 0;
            $globalOffset = $currentLineStart;
            $bufferSize = 1048576; // 1MB

            while (! feof($handle) || $currentRecordBuffer !== '') {
                $chunk = fread($handle, $bufferSize);
                if ($chunk === false && feof($handle)) {
                    break;
                }

                $chunkLen = $chunk === false ? 0 : strlen($chunk);
                $chunkPos = 0;

                while ($chunkPos < $chunkLen || ($chunkPos === 0 && feof($handle) && $currentRecordBuffer !== '')) {
                    if (! $inside) {
                        // While outside quotes, scan for the next newline or enclosure
                        $nextN = $chunk === false ? false : strpos($chunk, "\n", $chunkPos);
                        $nextR = $chunk === false ? false : strpos($chunk, "\r", $chunkPos);
                        $nextE = $chunk === false ? false : strpos($chunk, $enclosure, $chunkPos);

                        // Find the earliest event
                        $targets = [];
                        if ($nextN !== false) {
                            $targets[] = ['type' => 'newline', 'pos' => $nextN, 'len' => 1];
                        }
                        if ($nextR !== false) {
                            $targets[] = ['type' => 'newline', 'pos' => $nextR, 'len' => ($nextR + 1 < $chunkLen && $chunk[$nextR + 1] === "\n") ? 2 : 1];
                        }
                        if ($nextE !== false) {
                            $targets[] = ['type' => 'enclosure', 'pos' => $nextE, 'len' => 1];
                        }

                        if ($targets === []) {
                            // No events in the rest of this chunk
                            if ($chunk !== false) {
                                $currentRecordBuffer .= substr($chunk, $chunkPos);
                            }
                            $globalOffset += ($chunkLen - $chunkPos);
                            $chunkPos = $chunkLen;

                            if (feof($handle) && $currentRecordBuffer !== '') {
                                // Implicit row at EOF
                                $this->fastProcessRow(
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
                                $currentRecordBuffer = '';
                            }
                        } else {
                            // Sort targets by position
                            usort($targets, fn ($a, $b) => $a['pos'] <=> $b['pos']);
                            $event = $targets[0];

                            if ($chunk !== false) {
                                $piece = substr($chunk, $chunkPos, $event['pos'] - $chunkPos);
                                $currentRecordBuffer .= $piece;
                            }

                            if ($event['type'] === 'newline') {
                                // Complete row!
                                $this->fastProcessRow(
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
                                $globalOffset += ($event['pos'] - $chunkPos + $event['len']);
                                $currentLineStart = $globalOffset;
                                $chunkPos = $event['pos'] + $event['len'];
                                $currentRecordBuffer = '';
                            } else {
                                // Enclosure found, toggle state
                                $inside = true;
                                $globalOffset += ($event['pos'] - $chunkPos + 1);
                                $chunkPos = $event['pos'] + 1;
                            }
                        }
                    } else {
                        // While inside quotes, only look for the next enclosure
                        $nextE = $chunk === false ? false : strpos($chunk, $enclosure, $chunkPos);

                        if ($nextE === false) {
                            if ($chunk !== false) {
                                $currentRecordBuffer .= substr($chunk, $chunkPos);
                            }
                            $globalOffset += ($chunkLen - $chunkPos);
                            $chunkPos = $chunkLen;
                        } else {
                            if ($chunk !== false) {
                                $piece = substr($chunk, $chunkPos, $nextE - $chunkPos + 1);
                                $currentRecordBuffer .= $piece;
                            }
                            $inside = false;
                            $globalOffset += ($nextE - $chunkPos + 1);
                            $chunkPos = $nextE + 1;
                        }
                    }
                }
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
