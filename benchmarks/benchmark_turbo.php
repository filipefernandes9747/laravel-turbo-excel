<?php

require 'vendor/autoload.php';

// ═════════════════════════════════════════════════════════════════════════════
//  Minimal container bootstrap (no full Laravel app required)
//  Binds: Validator (header validation), Cache (progress), Log (metrics)
// ═════════════════════════════════════════════════════════════════════════════
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use Psr\Log\NullLogger;

$container = Container::getInstance();
$container->instance('validator', new ValidatorFactory(new Translator(new ArrayLoader, 'en'), $container));
$container->instance('cache', new CacheRepository(new ArrayStore));
$container->instance('log', new NullLogger);
Facade::setFacadeApplication($container);

// ─────────────────────────────────────────────────────────────────────────────
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use TurboExcel\Enums\Format;
use TurboExcel\Import\Concerns\OnEachChunk;
use TurboExcel\Import\Concerns\WithChunkReading;
use TurboExcel\Import\Concerns\WithHeaderRow;
use TurboExcel\Import\Concerns\WithHeaderValidation;
use TurboExcel\Import\ImportScanner;
use TurboExcel\Import\Readers\CsvReader;
use TurboExcel\Import\SegmentImporter;
use TurboExcel\Import\Segments\CsvReadSegment;
use TurboExcel\Import\Traits\Importable;

// ═════════════════════════════════════════════════════════════════════════════
//  Shared import class – implements the full typical interface set.
//  OnEachChunk accumulates rows in memory so we can assert correct count.
// ═════════════════════════════════════════════════════════════════════════════
class FullCheckImport implements OnEachChunk, WithChunkReading, WithHeaderRow, WithHeaderValidation
{
    use Importable;

    public int $rowsProcessed = 0;

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

    public function headerRow(): int
    {
        return 1;
    }

    public function chunkSize(): int
    {
        return 5000;
    }

    public function onChunk(Collection $chunk): void
    {
        $this->rowsProcessed += $chunk->count();
    }
}

// ═════════════════════════════════════════════════════════════════════════════
//  Helpers
// ═════════════════════════════════════════════════════════════════════════════
function section(string $title): void
{
    echo "\n┌─ {$title} ".str_repeat('─', max(0, 60 - strlen($title)))."\n";
}

function ok(string $msg): void
{
    echo "│  ✅  {$msg}\n";
}
function fail(string $msg): void
{
    echo "│  ❌  {$msg}\n";
}
function info(string $msg): void
{
    echo "│  ℹ️   {$msg}\n";
}

function assert_eq(mixed $got, mixed $expected, string $label): void
{
    $got === $expected
        ? ok("{$label} = ".number_format((int) $got).' ✓')
        : fail("{$label} = ".number_format((int) $got).' (expected '.number_format((int) $expected).')');
}

// ═════════════════════════════════════════════════════════════════════════════
//  CSV CHECKS
// ═════════════════════════════════════════════════════════════════════════════
$csvPath = 'import_test_500k.csv';
$csvExpected = 500_000;

echo str_repeat('═', 62)."\n";
echo "  TurboExcel Import Check – CSV + XLSX\n";
echo str_repeat('═', 62)."\n";

// ── 1. CsvReader layer ───────────────────────────────────────────────────────
section('CSV › CsvReader layer');

if (! file_exists($csvPath)) {
    fail("{$csvPath} not found – skipping all CSV checks.");
} else {
    // BOM
    $fh = fopen($csvPath, 'rb');
    CsvReader::skipUtf8BomIfAtStart($fh);
    $bomBytes = (int) ftell($fh);
    fclose($fh);
    $bomBytes === 0
        ? ok('No UTF-8 BOM detected (offset = 0)')
        : ok("UTF-8 BOM stripped – file pointer now at byte {$bomBytes}");

    // Parse first 3 rows
    $reader = new CsvReader($csvPath);
    $headerCells = null;
    $rowCount = 0;

    foreach ($reader->iterateByByteOffset(0, null, 1) as $row) {
        if ($rowCount === 0) {
            $headerCells = $row['cells'];
            info('Header  [row '.$row['rowIndex'].']: '.implode(' | ', $row['cells']));
        } else {
            info('Data    [row '.$row['rowIndex'].']: '
                .implode(' | ', array_slice($row['cells'], 0, 3)).' …');
        }
        if (++$rowCount >= 3) {
            break;
        }
    }

    // Column presence
    $expected = array_keys((new FullCheckImport)->headers());
    $missing = array_diff($expected, $headerCells ?? []);
    $missing === []
        ? ok('All required columns present in header row')
        : fail('Missing columns: '.implode(', ', $missing));

    // ── 2. ImportScanner ────────────────────────────────────────────────────
    section('CSV › ImportScanner  (Phase 1 + Phase 2)');

    $import = new FullCheckImport;
    $t = microtime(true);
    $scanner = new ImportScanner($import, $csvPath, Format::CSV, 5_000);
    $scan = $scanner->scan();
    $scanMs = (int) round((microtime(true) - $t) * 1_000);

    assert_eq($scan->totalRows, $csvExpected, 'Row count');
    ok('Segments built = '.count($scan->segments).'  (chunk size = 5,000)');
    ok("Scan time      = {$scanMs} ms");
    $scanMs < 1_000
        ? ok('Pre-progress-bar delay < 1 s 🚀')
        : fail("Pre-progress-bar delay = {$scanMs} ms  (target < 1,000 ms)");

    // ── 3. SegmentImporter – full end-to-end ────────────────────────────────
    section('CSV › SegmentImporter  (full end-to-end pipeline)');

    $import = new FullCheckImport;
    $segImporter = new SegmentImporter;
    $t = microtime(true);
    $result = $segImporter->run(
        $import,
        $csvPath,
        Format::CSV,
        new CsvReadSegment(0, null),    // full file from byte 0
        $scan->headerKeys,
    );
    $importMs = (int) round((microtime(true) - $t) * 1_000);
    $peakMb = round(memory_get_peak_usage(true) / 1024 / 1024, 1);

    assert_eq($result->processed, $csvExpected, 'Rows processed');
    assert_eq($result->failed, 0, 'Failures');
    ok("Import time = {$importMs} ms  |  Peak memory = {$peakMb} MB");
}

// ═════════════════════════════════════════════════════════════════════════════
//  XLSX CHECKS
// ═════════════════════════════════════════════════════════════════════════════
$xlsxPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'turbo_check_test.xlsx';
$xlsxExpected = 500;

// ── 4. Generate test XLSX ───────────────────────────────────────────────────
section("XLSX › Generating test file  ({$xlsxExpected} data rows)");

$writer = new XlsxWriter;
$writer->openToFile($xlsxPath);
$writer->addRow(Row::fromValues(
    ['date', 'description', 'deposits', 'with_drawls', 'balance']
));
for ($i = 1; $i <= $xlsxExpected; $i++) {
    $writer->addRow(Row::fromValues([
        date('Y-m-d'),
        "Transaction number {$i} with some extra text to make it realistic",
        round(mt_rand(100, 99_999) / 100, 2),
        round(mt_rand(100, 99_999) / 100, 2),
        round(mt_rand(10_000, 9_999_900) / 100, 2),
    ]));
}
$writer->close();

$sizeKb = round(filesize($xlsxPath) / 1024, 1);
ok("Written {$xlsxExpected} data rows + 1 header row");
ok('File: '.basename($xlsxPath)."  ({$sizeKb} KB)");

// ── 5. ImportScanner ────────────────────────────────────────────────────────
section('XLSX › ImportScanner');

$import = new FullCheckImport;
$t = microtime(true);
$scanner = new ImportScanner($import, $xlsxPath, Format::XLSX, 100);
$scan = $scanner->scan();
$scanMs = (int) round((microtime(true) - $t) * 1_000);

assert_eq($scan->totalRows, $xlsxExpected, 'Row count');
ok('Segments built = '.count($scan->segments).'  (chunk size = 100)');
ok("Scan time      = {$scanMs} ms");

if ($scan->headerKeys !== null) {
    ok('Header keys: '.implode(', ', $scan->headerKeys));
} else {
    fail('No header keys resolved from scan');
}

// ── 6. SegmentImporter – full end-to-end ────────────────────────────────────
section('XLSX › SegmentImporter  (full end-to-end pipeline)');

$import = new FullCheckImport;
$segImporter = new SegmentImporter;
$t = microtime(true);
$result = $segImporter->run(
    $import,
    $xlsxPath,
    Format::XLSX,
    null,               // SegmentImporter defaults to first sheet, full range
    $scan->headerKeys,
);
$importMs = (int) round((microtime(true) - $t) * 1_000);
$peakMb = round(memory_get_peak_usage(true) / 1024 / 1024, 1);

assert_eq($result->processed, $xlsxExpected, 'Rows processed');
assert_eq($result->failed, 0, 'Failures');
ok("Import time = {$importMs} ms  |  Peak memory = {$peakMb} MB");

// Cleanup
@unlink($xlsxPath);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n".str_repeat('═', 62)."\n";
echo "  Done.\n";
echo str_repeat('═', 62)."\n\n";
