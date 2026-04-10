<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

use Illuminate\Foundation\Bus\PendingDispatch;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TurboExcel\Enums\Format;
use TurboExcel\Facades\TurboExcel;

trait Exportable
{
    /**
     * Start the download for the export.
     */
    public function download(string $filename, ?Format $format = null): StreamedResponse
    {
        return TurboExcel::download($this, $filename, $format);
    }

    /**
     * Store the export on a storage disk.
     */
    public function store(string $filePath, string $disk = 'local', ?Format $format = null): string
    {
        return TurboExcel::store($this, $filePath, $disk, $format);
    }

    /**
     * Queue the export.
     */
    public function queue(string $filePath, string $disk = 'local', ?Format $format = null): PendingDispatch
    {
        return TurboExcel::queue($this, $filePath, $disk, $format);
    }

    /**
     * Stream the export directly.
     */
    public function stream(string $filename, ?Format $format = null): StreamedResponse
    {
        return TurboExcel::stream($this, $filename, $format);
    }

    /**
     * Export directly to a local path.
     */
    public function export(string $path, ?Format $format = null): string
    {
        return TurboExcel::export($this, $path, $format);
    }
}
