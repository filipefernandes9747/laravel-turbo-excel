<?php

declare(strict_types=1);

namespace TurboExcel\Import\Scanners;

use ZipArchive;

/**
 * Rapidly determines XLSX sheet dimensions by peeking at the worksheet XML dimension tag.
 */
final class XlsxQuickScanner
{
    /**
     * Get the row count of a specific sheet in an XLSX file.
     * 
     * @param  int  $sheetIndex  0-based sheet index.
     * @return int|null Row count if found, null otherwise.
     */
    public function getRowCount(string $path, int $sheetIndex = 0): ?int
    {
        if (! class_exists('ZipArchive')) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }

        // Worksheet XML files are typically at xl/worksheets/sheetN.xml
        // The index might not match exactly if sheets were deleted/renamed,
        // but for a fresh scan, this is the most common path.
        $entryName = "xl/worksheets/sheet" . ($sheetIndex + 1) . ".xml";
        $xmlContent = $zip->getFromName($entryName);
        $zip->close();

        if ($xmlContent === false) {
            return null;
        }

        // Look for <dimension ref="A1:E1000"/> or <dimension ref="A1"/>
        // We want the last number in the range.
        if (preg_match('/<dimension\s+ref="[A-Z0-9]+:?([A-Z]+)([0-9]+)"/i', $xmlContent, $matches)) {
            return (int) $matches[2];
        }

        return null;
    }
}
