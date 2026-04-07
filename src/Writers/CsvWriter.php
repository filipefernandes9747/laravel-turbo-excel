<?php

declare(strict_types=1);

namespace TurboExcel\Writers;

use TurboExcel\Exceptions\TurboExcelException;
use TurboExcel\Writers\Contracts\WriterInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer;
use OpenSpout\Writer\CSV\Options;

final class CsvWriter implements WriterInterface
{
    private Writer $writer;
    private ?Options $options = null;

    public function open(string $path): void
    {
        $this->writer = new Writer($this->options);
        $this->writer->openToFile($path);
    }

    public function applyOptions(object $export): void
    {
        if ($export instanceof \TurboExcel\Concerns\WithCsvOptions) {
            $this->options = new Options();
            $this->options->FIELD_DELIMITER = $export->delimiter();
            $this->options->FIELD_ENCLOSURE = $export->enclosure();
            $this->options->SHOULD_ADD_BOM = $export->addBom();
        }
    }

    /**
     * CSV does not support multiple sheets.
     *
     * @throws TurboExcelException
     */
    public function addSheet(string $name, bool $first = false): void
    {
        if (! $first) {
            throw new TurboExcelException(
                'CSV format does not support multiple sheets. Use Format::XLSX for multi-sheet exports.',
            );
        }
    }

    public function writeRow(\OpenSpout\Common\Entity\Row $row): void
    {
        $this->writer->addRow($row);
    }

    public function close(): void
    {
        $this->writer->close();
    }
}
