<?php

declare(strict_types=1);

namespace TurboExcel\Import;

use TurboExcel\Concerns\WithCsvOptions;
use TurboExcel\Enums\Format;
use TurboExcel\Exceptions\TurboExcelException;
use TurboExcel\Import\Concerns\WithHeaderRow;
use TurboExcel\Import\Pipeline\HeaderProcessor;
use TurboExcel\Import\Readers\CsvReader;
use TurboExcel\Import\Segments\CsvReadSegment;
use TurboExcel\Import\Segments\XlsxReadSegment;
use TurboExcel\Import\Scanners\XlsxQuickScanner;

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
            Format::CSV  => $this->scanCsv(),
            Format::XLSX => $this->scanXlsx(),
        };
    }

    private function scanCsv(): ImportScan
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new TurboExcelException("Cannot open CSV: {$this->path}");
        }

        [$delimiter, $enclosure, $escape] = $this->csvOptions();

        try {
            CsvReader::skipUtf8BomIfAtStart($handle);

            $headerRow = $this->import instanceof WithHeaderRow ? max(1, $this->import->headerRow()) : null;
            $headerKeys = null;
            $rowNum = 1;

            /** @var list<int> $segmentStarts */
            $segmentStarts = [];
            /** @var list<int> $segmentRowNumbers */
            $segmentRowNumbers = [];
            $dataCount = 0;

            while (! feof($handle)) {
                $posBefore = ftell($handle);
                if ($posBefore === false) {
                    break;
                }

                $line = fgetcsv($handle, 0, $delimiter, $enclosure, $escape);
                if ($line === false) {
                    break;
                }

                if ($this->isCsvRecordEmpty($line)) {
                    ++$rowNum;

                    continue;
                }

                if ($headerRow !== null && $rowNum === $headerRow) {
                    /** @var list<string> $cells */
                    $cells = $this->normalizeCsvLine($line);
                    HeaderProcessor::validateHeaders($cells, $this->import);
                    $headerKeys = HeaderProcessor::buildHeaderKeys($cells, $this->import);
                    ++$rowNum;

                    continue;
                }

                if ($headerRow !== null && $rowNum < $headerRow) {
                    ++$rowNum;

                    continue;
                }

                if ($dataCount % $this->chunkSize === 0) {
                    $segmentStarts[] = (int) $posBefore;
                    $segmentRowNumbers[] = $rowNum;
                }

                ++$dataCount;
                ++$rowNum;
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

        $quickScanner = new XlsxQuickScanner();
        $quickRowCount = $quickScanner->getRowCount($this->path, $this->xlsxSheetIndex ?? 0);

        if ($quickRowCount !== null) {
            return $this->fastScanXlsx($quickRowCount, $headerRow);
        }

        $openSpout = new \OpenSpout\Reader\XLSX\Reader();
        $openSpout->open($this->path);

        try {
            $sheetIndexTarget = $this->xlsxSheetIndex;
            $currentSheetIndex = 0;

            foreach ($openSpout->getSheetIterator() as $sheet) {
                if ($sheetIndexTarget !== null) {
                    if ($currentSheetIndex !== $sheetIndexTarget) {
                        ++$currentSheetIndex;

                        continue;
                    }
                } elseif ($currentSheetIndex > 0) {
                    break;
                }

                $rowIndex = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    ++$rowIndex;

                    if ($headerRow !== null && $rowIndex === $headerRow) {
                        $cells = \TurboExcel\Import\Readers\XlsxReader::indexedCells($row);
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

                    ++$dataCount;
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
            $openSpout = new \OpenSpout\Reader\XLSX\Reader();
            $openSpout->open($this->path);

            try {
                $sheetIndexTarget = $this->xlsxSheetIndex;
                $currentSheetIndex = 0;

                foreach ($openSpout->getSheetIterator() as $sheet) {
                    if ($sheetIndexTarget !== null) {
                        if ($currentSheetIndex !== $sheetIndexTarget) {
                            ++$currentSheetIndex;

                            continue;
                        }
                    } elseif ($currentSheetIndex > 0) {
                        break;
                    }

                    $rowIndex = 0;
                    foreach ($sheet->getRowIterator() as $row) {
                        if (++$rowIndex === $headerRow) {
                            $cells = \TurboExcel\Import\Readers\XlsxReader::indexedCells($row);
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
