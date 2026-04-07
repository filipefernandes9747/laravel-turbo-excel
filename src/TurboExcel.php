<?php

declare(strict_types=1);

namespace TurboExcel;

use TurboExcel\Enums\Format;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turbo-Excel service class.
 *
 * Resolved via the Facade — call through \TurboExcel\Facades\TurboExcel:
 *
 *   TurboExcel::download(new UsersExport(), 'users.xlsx');
 *   TurboExcel::store(new UsersExport(), 'exports/users.xlsx', disk: 's3');
 *   TurboExcel::export(new UsersExport(), '/abs/path/report.xlsx');
 */
class TurboExcel
{
    // ---------------------------------------------------------------------------
    // Output methods
    // ---------------------------------------------------------------------------

    /**
     * Queue the export to a Laravel Storage disk.
     * Returns a PendingDispatch, which allows chaining methods like ->onQueue() or ->onConnection().
     * Can also be used inside a Bus::batch([...]).
     *
     * @param  string  $filePath  Relative path on the disk (e.g. 'exports/users.xlsx').
     * @param  string  $disk  Storage disk name (default: 'local').
     */
    public function queue(
        object $export,
        string $filePath,
        string $disk = 'local',
        ?Format $format = null,
    ): \Illuminate\Foundation\Bus\PendingDispatch {
        return \TurboExcel\Jobs\ExportJob::dispatch($export, $filePath, $disk, $format);
    }

    /**
     * Stream the export directly to the browser without writing to disk.
     * Fastest output, but if an exception occurs mid-export, the downloaded file will be corrupted.
     *
     * @param  string  $filename  The suggested filename for the download.
     */
    public function stream(object $export, string $filename, ?Format $format = null): StreamedResponse
    {
        $format ??= Format::fromFilename($filename);

        return response()->streamDownload(
            callback: function () use ($export, $format): void {
                (new Exporter($export, $format))->export('php://output');
            },
            name: $filename,
            headers: [
                'Content-Type' => $format->mimeType(),
            ]
        );
    }

    /**
     * Stream the export to the browser via a temporary local file.
     * Safer than `stream()` as it ensures the export succeeds fully before downloading.
     */
    public function download(object $export, string $filename, ?Format $format = null): StreamedResponse
    {
        $format ??= Format::fromFilename($filename);

        return response()->streamDownload(
            callback: function () use ($export, $format): void {
                $tmp = $this->writeTmp($export, $format);

                try {
                    $handle = fopen($tmp, 'rb');
                    fpassthru($handle);
                    fclose($handle);
                } finally {
                    @unlink($tmp);
                }
            },
            name: $filename,
            headers: ['Content-Type' => $format->mimeType()],
        );
    }

    /**
     * Write the export to a Laravel Storage disk and return the stored path.
     *
     * @param  string  $path  Relative path on the disk (e.g. 'exports/users.xlsx').
     * @param  string  $disk  Storage disk name (default: 'local').
     */
    public function store(
        object $export,
        string $path,
        string $disk = 'local',
        ?Format $format = null,
    ): string {
        $format ??= Format::fromFilename($path);
        $tmp     = $this->writeTmp($export, $format);

        try {
            Storage::disk($disk)->put($path, fopen($tmp, 'rb'));
        } finally {
            @unlink($tmp);
        }

        return $path;
    }

    /**
     * Write the export directly to an absolute filesystem path and return it.
     */
    public function export(object $export, string $path, ?Format $format = null): string
    {
        $format ??= Format::fromFilename($path);

        (new Exporter($export, $format))->export($path);

        return $path;
    }

    // ---------------------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------------------

    /**
     * Write to a temp file and return its path. Caller must clean up.
     */
    private function writeTmp(object $export, Format $format): string
    {
        $dir = storage_path('app/turbo-excel-tmp');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, recursive: true);
        }

        $tmp = tempnam($dir, 'turbo-excel-') . '.' . $format->extension();

        (new Exporter($export, $format))->export($tmp);

        return $tmp;
    }
}
