<?php

declare(strict_types=1);

namespace TurboExcel\Writers;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use TurboExcel\Writers\Contracts\WriterInterface;

final class XlsxWriter implements WriterInterface
{
    private Writer $writer;

    private ?\OpenSpout\Writer\XLSX\Options $options = null;

    public function open(string $path): void
    {
        $this->writer = new Writer($this->options);
        $this->writer->openToFile($path);
    }

    public function applyOptions(object $export): void
    {
        $this->options = new \OpenSpout\Writer\XLSX\Options();

        if ($export instanceof \TurboExcel\Concerns\WithProperties) {
            $props = $export->properties();
            $metadata = new \OpenSpout\Writer\XLSX\Properties(
                title: $props['title'] ?? 'Untitled Spreadsheet',
                subject: $props['subject'] ?? null,
                creator: $props['creator'] ?? 'Turbo-Excel',
                keywords: $props['keywords'] ?? null,
                description: $props['description'] ?? null,
                category: $props['category'] ?? null,
            );
            $this->options->setProperties($metadata);
        }

        if ($export instanceof \TurboExcel\Concerns\WithColumnWidths) {
            foreach ($export->columnWidths() as $column => $width) {
                $index = $this->columnIndex($column);
                $this->options->setColumnWidth($width, $index + 1);
            }
        }
    }

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

    public function writeRow(Row $row): void
    {
        $this->writer->addRow($row);
    }

    public function close(): void
    {
        $this->writer->close();
    }
}
