<?php

declare(strict_types=1);

$datasets = [
    'Small'    => 1_000,
    'Medium'   => 10_000,
    'Large'    => 100_000,
    //'Enormous' => 500_000, // Skipped for speed in automated benchmark runs
];

$packages = ['turbo', 'maatwebsite'];
$types = ['generator', 'query'];

echo "Starting Benchmarks...\n";

$results = [];

foreach ($types as $type) {
    echo "\n=== Data Source: " . strtoupper($type) . " ===\n";
    echo "------------------------------------------------------\n";
    echo sprintf("%-14s | %-12s | %-10s | %-10s\n", 'Dataset', 'Package', 'Time (s)', 'Peak Mem (MB)');
    echo "------------------------------------------------------\n";

    foreach ($datasets as $name => $rows) {
        if ($type === 'query') {
            // Seed DB just before testing this dataset size
            exec(sprintf("php setup_db.php %d", $rows));
        }

        foreach ($packages as $pkg) {
            $cmd = sprintf("php bench.php %s %d %s", escapeshellarg($pkg), $rows, escapeshellarg($type));
            
            $output = [];
            $returnVar = 0;
            exec($cmd, $output, $returnVar);
            
            $raw = implode("\n", $output);
            $data = json_decode($raw, true);

            if (!$data || isset($data['error'])) {
                $time = 'ERROR';
                $mem = 'OOM/Crash';
                if (isset($data['error'])) {
                    $mem = 'Failed';
                }
            } else {
                $time = number_format($data['time_seconds'], 2);
                $mem = number_format($data['peak_memory_bytes'] / 1024 / 1024, 2);
            }

            printf("%-14s | %-12s | %-10s | %-10s\n", $name . " ($rows)", $pkg, $time, $mem);
        }
        echo "------------------------------------------------------\n";
    }
}

