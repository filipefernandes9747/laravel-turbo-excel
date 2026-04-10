<?php

declare(strict_types=1);

namespace TurboExcel\Writers\Contracts;

use OpenSpout\Common\Entity\Row;

interface WriterInterface
{
    /**
     * Open the writer and point it at a file path.
     */
    public function open(string $path): void;

    /**
     * Set the name of the current (first) sheet or add a new sheet and make
     * it current (for subsequent sheets in a multi-sheet export).
     *
     * @param  string  $name  Sheet name.
     * @param  bool  $first  True when this is the very first sheet; the writer
     *                       should rename the existing default sheet instead of
     *                       appending a new one.
     */
    public function addSheet(string $name, bool $first = false): void;

    /**
     * Apply writer-specific options based on the export concerns.
     */
    public function applyOptions(object $export): void;

    /**
     * Write a single row using precise OpenSpout Cell objects to support styles.
     */
    public function writeRow(Row $row): void;

    /**
     * Flush and close the file.
     */
    public function close(): void;
}
