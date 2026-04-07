# Fast-Excel

[![Tests](https://github.com/iberdola/fast-excel/actions/workflows/tests.yml/badge.svg)](https://github.com/iberdola/fast-excel/actions)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%2B-red)](https://laravel.com)

Memory-efficient Excel and CSV exports for Laravel, powered by [openspout/openspout](https://github.com/openspout/openspout).

Export classes implement lightweight **Concern interfaces** — the same pattern as Laravel Excel — giving you full control with zero magic.

---

## Installation

```bash
composer require filipefernandes/fast-excel
```

---

## Core Concept

Create a plain PHP class and implement the concerns you need:

```php
// app/Exports/UsersExport.php

use FastExcel\Concerns\FromQuery;
use FastExcel\Concerns\WithHeadings;
use FastExcel\Concerns\WithMapping;
use FastExcel\Concerns\WithTitle;
use FastExcel\Concerns\WithChunkSize;

class UsersExport implements FromQuery, WithTitle, WithHeadings, WithMapping, WithChunkSize
{
    public function query(): Builder
    {
        return User::query()->where('active', true);
    }

    public function title(): string   { return 'Active Users'; }

    public function headings(): array { return ['Name', 'Email', 'Joined']; }

    public function map($row): array
    {
        return [$row->name, $row->email, $row->created_at->format('Y-m-d')];
    }

    public function chunkSize(): int { return 500; }
}
```

Then call it from a controller:

```php
use FastExcel\Facades\FastExcel;

// Stream to browser
return FastExcel::download(new UsersExport(), 'users.xlsx');

// Write to a Storage disk
FastExcel::store(new UsersExport(), 'exports/users.xlsx', disk: 's3');

// Write to the filesystem
FastExcel::export(new UsersExport(), storage_path('exports/users.xlsx'));
```

---

## Data Source Concerns

Implement **one** of the following to tell Fast-Excel where your data comes from.

| Concern          | Method                     | Best for                                            |
| ---------------- | -------------------------- | --------------------------------------------------- |
| `FromQuery`      | `query(): Builder`         | Large datasets — streamed via `lazy()`, flat memory |
| `FromCollection` | `collection(): Collection` | Small, pre-loaded Illuminate Collections            |
| `FromArray`      | `array(): array`           | Plain PHP arrays                                    |
| `FromGenerator`  | `generator(): \Generator`  | Custom lazy sources, external API pages             |

### `FromQuery` (chunked, memory-safe)

```php
class OrdersExport implements FromQuery
{
    public function query(): Builder
    {
        return Order::query()->with('customer')->latest();
    }
}
```

Rows are fetched in chunks via Eloquent's `lazy()`. Override the chunk size with `WithChunkSize`.

### `FromArray`

```php
class SimpleExport implements FromArray
{
    public function array(): array
    {
        return [
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'Bob',   'email' => 'bob@example.com'],
        ];
    }
}
```

### `FromCollection`

```php
class ProductExport implements FromCollection
{
    public function collection(): Collection
    {
        return Product::all();
    }
}
```

### `FromGenerator`

```php
class LargeExport implements FromGenerator
{
    public function generator(): \Generator
    {
        foreach (File::lines('/data/huge.csv') as $line) {
            yield str_getcsv($line);
        }
    }
}
```

---

## Formatting Concerns

### `WithHeadings`

Explicit column headers written as the first row:

```php
class UsersExport implements FromArray, WithHeadings
{
    public function headings(): array { return ['Full Name', 'Email']; }
    // ...
}
```

> If `WithHeadings` is **not** implemented, headings are auto-derived from the first row's array keys.

### `WithMapping`

Transform each row before it is written. Combine with `WithHeadings` for labelled columns:

```php
class UsersExport implements FromQuery, WithHeadings, WithMapping
{
    public function headings(): array { return ['Name', 'Email', 'Joined']; }

    public function map($user): array
    {
        return [$user->name, $user->email, $user->created_at->toDateString()];
    }
}
```

### `WithTitle`

Set the sheet tab name (XLSX only):

```php
class UsersExport implements FromArray, WithTitle
{
    public function title(): string { return 'Users'; }
}
```

### `WithChunkSize`

Override the default chunk size when using `FromQuery`:

```php
class BigExport implements FromQuery, WithChunkSize
{
    public function chunkSize(): int { return 2000; }
}
```

### `WithColumnFormatting` (XLSX only)

Set native Excel number formats (Currency, Dates, etc.) without losing precision:

```php
use FastExcel\Concerns\WithColumnFormatting;

class FinancialExport implements FromArray, WithColumnFormatting
{
    public function array(): array { ... }

    public function columnFormats(): array
    {
        return [
            'B' => '#,##0.00 €', // Using column letter
            2   => 'dd/mm/yyyy', // Using 0-based index
        ];
    }
}
```

### `WithStyles` (XLSX only)

Apply rich visual styles (fonts, colors, borders) while streaming row-by-row (zero memory overhead):

```php
use FastExcel\Concerns\WithStyles;
use OpenSpout\Common\Entity\Style\Style;

class StyledExport implements FromArray, WithStyles
{
    public function array(): array { ... }

    public function styles(): array
    {
        return [
            // Style the special first header row
            'header' => (new Style())->setFontBold()->setBackgroundColor('FFE6E6E6'),
            
            // Style an entire column
            'A' => (new Style())->setFontItalic(),
        ];
    }
}
```

### `WithCsvOptions` (CSV only)

Customize delimiter, enclosure, and Unicode BOM settings for CSV files:

```php
use FastExcel\Concerns\WithCsvOptions;

class CustomCsvExport implements FromQuery, WithCsvOptions
{
    public function query() { ... }

    public function delimiter(): string { return ';'; }
    public function enclosure(): string { return "'"; }
    public function addBom(): bool      { return true; } // Better UTF-8 support in Excel
}
```

---

## Multi-Sheet Exports (XLSX only)

Implement `WithMultipleSheets` and return an array of sheet export objects. Each sheet object may implement any concern independently.

```php
class ReportExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            new UsersSheet(),   // implements FromQuery, WithTitle, WithHeadings, WithMapping
            new OrdersSheet(),  // implements FromQuery, WithTitle, WithHeadings, WithMapping
        ];
    }
}

FastExcel::download(new ReportExport(), 'monthly-report.xlsx');
```

---

## Output Methods

| Method                                              | Description                   |
| --------------------------------------------------- | ----------------------------- |
| `FastExcel::stream($export, 'file.xlsx')`           | Stream directly to browser (Fastest, zero-IO) |
| `FastExcel::download($export, 'file.xlsx')`         | Stream to browser via local temporary file    |
| `FastExcel::store($export, 'path/file.xlsx', 's3')` | Write to a Storage disk       |
| `FastExcel::export($export, '/abs/path/file.xlsx')` | Write to filesystem path      |
| `FastExcel::queue($export, 'path/file.xlsx', 's3')` | Dispatch to a Laravel Queue   |

The export format is auto-detected from the filename extension (`.xlsx` → XLSX, `.csv` → CSV).
Pass an explicit `Format` case to override:

```php
use FastExcel\Enums\Format;

FastExcel::download(new UsersExport(), 'users.xlsx', Format::CSV);
```

---

## Format Enum

```php
use FastExcel\Enums\Format;

Format::XLSX  // application/vnd.openxmlformats-...
Format::CSV   // text/csv
```

---

## Queued Background Exports

When exporting massive amounts of data, you can dispatch the export to a Laravel Queue entirely in the background. Because `FastExcel` uses `OpenSpout`, memory is kept incredibly flat, handling millions of rows efficiently within a single job.

```php
FastExcel::queue(new UsersExport(), 'reports/huge-report.xlsx', 's3')
    ->onConnection('redis')
    ->onQueue('exports');
```

**Using with Laravel Batches (Progress Tracking)**

You can also dispatch the export job directly inside a Laravel Batch to track completion percentages! `FastExcel` automatically breaks your data into chunks and updates the batch progress internally as it writes to the file.

```php
use Illuminate\Support\Facades\Bus;
use FastExcel\Jobs\ExportJob;

$batch = Bus::batch([
    new ExportJob(new UsersExport(), 'reports/users.xlsx', disk: 's3')
])->name('Daily Users Export')->dispatch();

// Later check progress in your UI
echo $batch->progress(); // e.g. 50%
```

---

## Debugging / Profiling

When dealing with extremely large datasets (millions of rows) in local development, it can be useful to see exactly how much memory the OpenSpout stream is consuming and how fast the database is chunking rows.

Implement the `FastExcel\Concerns\WithDebug` interface on your export class to automatically emit detailed debugging logs to your default Laravel Log (`storage/logs/laravel.log`).

```php
use FastExcel\Concerns\FromQuery;
use FastExcel\Concerns\WithDebug;

class UsersExport implements FromQuery, WithDebug
{
    // ...
}
```

This will log:
1. Export start time, target path, and Export class name.
2. An update log every `5,000` rows tracking peak memory usage (so you are aware if your Eloquent hydration is consuming too much RAM).
3. Export completion time (in seconds) and exact final chunk sizes.

---

## Testing

```bash
composer test
```

---

## License

MIT
