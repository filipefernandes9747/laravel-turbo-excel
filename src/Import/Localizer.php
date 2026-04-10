<?php

declare(strict_types=1);

namespace TurboExcel\Import;

use Illuminate\Support\Facades\Storage;
use TurboExcel\Exceptions\TurboExcelException;

/**
 * Handles downloading remote storage files to local temporary storage for processing.
 */
final class Localizer
{
    /**
     * Download a file from a disk to a temporary local path.
     * 
     * @return string The absolute local path to the file.
     * @throws TurboExcelException if the file cannot be downloaded.
     */
    public function localize(string $path, string $disk): string
    {
        $storage = Storage::disk($disk);
        
        if (! $storage->exists($path)) {
            throw new TurboExcelException("File [{$path}] not found on disk [{$disk}].");
        }

        $dir = storage_path('app/turbo-excel-tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, recursive: true);
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $tmpPath = tempnam($dir, 'turbo-excel-import-') . ($extension ? '.' . $extension : '');

        $stream = $storage->readStream($path);
        if ($stream === null) {
            throw new TurboExcelException("Cannot open stream for [{$path}] on disk [{$disk}].");
        }

        $localStream = fopen($tmpPath, 'wb');
        if ($localStream === false) {
            throw new TurboExcelException("Cannot create local temporary file at [{$tmpPath}].");
        }

        stream_copy_to_stream($stream, $localStream);
        
        fclose($stream);
        fclose($localStream);

        return $tmpPath;
    }

    /**
     * Clean up a localized temporary file.
     */
    public function cleanup(string $localPath): void
    {
        if (file_exists($localPath) && str_contains($localPath, 'turbo-excel-tmp')) {
            @unlink($localPath);
        }
    }
}
