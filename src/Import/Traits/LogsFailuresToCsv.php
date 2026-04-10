<?php

declare(strict_types=1);

namespace TurboExcel\Import\Traits;

use Illuminate\Validation\ValidationException;

trait LogsFailuresToCsv
{
    /**
     * @param  array<string, mixed>  $row
     */
    public function onFailure(array $row, \Throwable $e): void
    {
        $path = $this->failuresExportPath();
        
        // Make sure the directory exists
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $handle = fopen($path, 'ab');
        if ($handle === false) {
            return;
        }

        // Lock to securely handle concurrent Queue workers appending rows
        flock($handle, LOCK_EX);

        try {
            $reason = $e instanceof ValidationException
                ? implode(' ', $e->validator->errors()->all())
                : $e->getMessage();

            $outputRow = $row;
            // Append the error reason safely to the end of the CSV line
            $outputRow['__error'] = $reason;

            fputcsv($handle, array_values($outputRow));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Define the target absolute path where failures will be exported.
     */
    public function failuresExportPath(): string
    {
        return storage_path('app/import-failures.csv');
    }
}
