<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Orchestra\Testbench\Foundation\Application;
use TurboExcel\TurboExcelServiceProvider;
use Maatwebsite\Excel\ExcelServiceProvider;
use Benchmarks\TurboExport;
use Benchmarks\MaatExport;
use Benchmarks\TurboQueryExport;
use Benchmarks\MaatQueryExport;
use TurboExcel\Facades\TurboExcel;
use Maatwebsite\Excel\Facades\Excel;

if ($argc < 4) {
    echo "Usage: php bench.php <package> <rows> <type>\n";
    exit(1);
}

$package = $argv[1];
$rows = (int) $argv[2];
$type = $argv[3]; // 'generator' or 'query'

ini_set('memory_limit', '-1');

$app = Application::create(basePath: __DIR__);

if ($type === 'query') {
    $app['config']->set('database.default', 'sqlite');
    $app['config']->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => __DIR__ . '/database.sqlite',
        'prefix' => '',
    ]);
}

$app->register(TurboExcelServiceProvider::class);
$app->register(ExcelServiceProvider::class);

$path = __DIR__ . '/output_' . $package . '_' . $rows . '.xlsx';
if (file_exists($path)) {
    unlink($path);
}

gc_collect_cycles();
$memStart = memory_get_usage();
$timeStart = microtime(true);

try {
    if ($package === 'turbo') {
        $export = $type === 'query' ? new TurboQueryExport() : new TurboExport($rows);
        TurboExcel::export($export, $path);
    } else {
        $export = $type === 'query' ? new MaatQueryExport() : new MaatExport($rows);
        Excel::store($export, 'output_' . $package . '_' . $rows . '.xlsx', 'local');
        $storagePath = storage_path('app/output_' . $package . '_' . $rows . '.xlsx');
        if (file_exists($storagePath)) {
            rename($storagePath, $path);
        }
    }
} catch (\Throwable $e) {
    echo json_encode([
        'error' => $e->getMessage()
    ]);
    exit(1);
}

$timeSeconds = microtime(true) - $timeStart;
$memPeak = memory_get_peak_usage() - $memStart;
$fileSize = file_exists($path) ? filesize($path) : 0;

if (file_exists($path)) {
    unlink($path);
}

echo json_encode([
    'success' => true,
    'time_seconds' => $timeSeconds,
    'peak_memory_bytes' => $memPeak,
    'file_size_bytes' => $fileSize,
]);
