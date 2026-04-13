<?php

use TurboExcel\Enums\Format;
use TurboExcel\Import\Concerns\WithChunkReading;
use TurboExcel\Import\Concerns\WithHeaderRow;
use TurboExcel\Import\Concerns\WithHeaderValidation;
use TurboExcel\Import\Concerns\WithProgressBar;
use TurboExcel\Import\ImportScanner;
use TurboExcel\Import\Traits\Importable;

class BenchmarkImport implements WithChunkReading, WithHeaderRow, WithHeaderValidation, WithProgressBar
{
    use Importable;

    public function headerRow(): int
    {
        return 1;
    }

    public function headers(): array
    {
        return [
            'date' => 'required',
            'description' => 'required',
            'deposits' => 'required',
            'with_drawls' => 'required',
            'balance' => 'required',
        ];
    }

    public function chunkSize(): int
    {
        return 5000;
    }
}

test('scanner performance on 10k rows', function () {
    $path = base_path('import_test_10k_auto.csv');

    if (! file_exists($path)) {
        $handle = fopen($path, 'w');
        fputcsv($handle, ['date', 'description', 'deposits', 'with_drawls', 'balance']);
        for ($i = 0; $i < 10000; $i++) {
            fputcsv($handle, ['2026-04-13', "Row $i", '100.00', '0.00', '100.00']);
        }
        fclose($handle);
    }

    echo "\n--- TurboExcel Benchmark (10k rows) ---\n";

    $start = microtime(true);

    $scanner = new ImportScanner(new BenchmarkImport, $path, Format::CSV, 5000);
    $scan = $scanner->scan();

    $end = microtime(true);
    $duration = $end - $start;

    echo 'Total Rows: '.number_format($scan->totalRows)."\n";
    echo 'Scan Time: '.number_format($duration, 4)." seconds\n";
    echo "----------------------------------------\n";

    expect($scan->totalRows)->toBe(10000);
    expect($duration)->toBeLessThan(1.5);
});
