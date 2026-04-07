<?php

declare(strict_types=1);

namespace TurboExcel\Writers;

use TurboExcel\Writers\Contracts\WriterInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

final class XlsxWriter implements WriterInterface
{
    private Writer $writer;

    public function open(string $path): void
    {
        $this->writer = new Writer();
        $this->writer->openToFile($path);
    }

    public function applyOptions(object $export): void
    {
        // No XLSX-specific options yet.
    }

    public function addSheet(string $name, bool $first = false): void
    {
        if ($first) {
            // Rename the already-created default first sheet.
            $this->writer->getCurrentSheet()->setName($name);

            return;
        }

        // Append a brand-new sheet and activate it.
        $this->writer->addNewSheetAndMakeItCurrent();
        $this->writer->getCurrentSheet()->setName($name);
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
