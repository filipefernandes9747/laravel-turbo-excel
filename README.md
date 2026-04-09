# Laravel Turbo Excel

[![Tests](https://github.com/filipefernandes/laravel-turbo-excel/actions/workflows/tests.yml/badge.svg)](https://github.com/filipefernandes/laravel-turbo-excel/actions)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-10--12-red)](https://laravel.com)

Memory-efficient Excel and CSV exports for Laravel, powered by [openspout/openspout](https://github.com/openspout/openspout).

Export classes implement lightweight **Concern interfaces** — the same pattern as Laravel Excel — giving you full control with zero magic.

---

## Installation

```bash
composer require filipefernandes/laravel-turbo-excel
```

---

## Core Concept

Create a plain PHP class and implement the concerns you need:

```php
// app/Exports/UsersExport.php

use TurboExcel\Concerns\FromQuery;
use TurboExcel\Concerns\WithHeadings;
use TurboExcel\Concerns\WithMapping;
use TurboExcel\Concerns\WithTitle;
use TurboExcel\Concerns\WithChunkSize;

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
use TurboExcel\Facades\TurboExcel;

// Stream directly to browser (Fastest, zero-IO)
return TurboExcel::stream(new UsersExport(), 'users.xlsx');

// Stream to browser via local temporary file
return TurboExcel::download(new UsersExport(), 'users.xlsx');

// Write to a Storage disk
TurboExcel::store(new UsersExport(), 'exports/users.xlsx', disk: 's3');

// Write to the filesystem
TurboExcel::export(new UsersExport(), storage_path('exports/users.xlsx'));
```

### Or using the `Exportable` trait

Add the `Exportable` trait to your export class to trigger exports fluently:

```php
use TurboExcel\Concerns\Exportable;

class UsersExport implements FromQuery
{
    use Exportable;
    // ...
}

// Then in your controller:
return (new UsersExport())->download('users.xlsx');
```

---

## Data Source Concerns

Implement **one** of the following to tell Turbo-Excel where your data comes from.

| Concern          | Method                     | Best for                                            |
| ---------------- | -------------------------- | --------------------------------------------------- |
| `FromQuery`      | `query(): Builder`         | Large datasets — streamed via `lazy()`, flat memory |
| `FromCollection` | `collection(): Collection` | Small, pre-loaded Illuminate Collections            |
| `FromArray`      | `array(): array`           | Plain PHP arrays                                    |
| `FromGenerator`  | `generator(): \Generator`  | Custom lazy sources, external API pages             |

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
use TurboExcel\Concerns\WithColumnFormatting;

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
use TurboExcel\Concerns\WithStyles;
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
use TurboExcel\Concerns\WithCsvOptions;

class CustomCsvExport implements FromQuery, WithCsvOptions
{
    public function query() { ... }

    public function delimiter(): string { return ';'; }
    public function enclosure(): string { return "'"; }
    public function addBom(): bool      { return true; } // Better UTF-8 support in Excel
}
```

### `WithAnonymization` 🛡️

Declaratively mask sensitive data (PII/GDPR) across your export without cluttering your mapping logic. It uses a high-performance in-place replacement loop.

```php
use TurboExcel\Concerns\WithAnonymization;

class UsersExport implements FromQuery, WithAnonymization
{
    public function query() { ... }

    // Optional: Determine if anonymization should run (defaults to true)
    public function isAnonymizationEnabled(): bool
    {
        return $this->user->isAdmin() === false;
    }

    // Column keys to mask (mapped or raw keys)
    public function anonymizeColumns(): array
    {
        return ['email', 'phone_number', 'address'];
    }

    // Replacement string (default is empty string)
    public function anonymizeReplacement(): string
    {
        return '[HIDDEN]';
    }
}
```

---

## Multi-Sheet Exports (XLSX only)

Implement `WithMultipleSheets` and return an array of sheet export objects. Each sheet object may implement any concern independently.

```php
TurboExcel::download(new ReportExport(), 'monthly-report.xlsx');
```

### `WithQuerySplitBySheet` ⚡

Optimized for large datasets sitting in a single flat table. It uses **one single ordered query** and automatically splits it into multiple sheets. You can return different sheet handler objects for different segments of the query.

> [!IMPORTANT]
> Your query **must be ordered** by the column you are splitting by.

```php
use TurboExcel\Concerns\WithQuerySplitBySheet;
use TurboExcel\Concerns\WithHeadings;
use TurboExcel\Concerns\WithMapping;
use TurboExcel\Concerns\WithTitle;

class FastMultiSheetExport implements WithQuerySplitBySheet
{
    public function query()
    {
        // 1. Query must be ordered
        return DB::table('reports')->orderBy('category');
    }

    public function splitByColumn(): string
    {
        // 2. The column triggers a new sheet whenever its value changes
        return 'category';
    }

    public function sheet(mixed $row): object
    {
        // 3. Return a handler for this sheet. 
        // It can implement any concern (Headings, Mapping, Styles, etc.)
        return match($row->category) {
            'VIP'      => new VipSheetHandler(),
            'Standard' => new StandardSheetHandler(),
            default    => new DefaultSheetHandler(),
        };
    }
}

class VipSheetHandler implements WithTitle, WithHeadings, WithMapping
{
    public function title(): string   { return '⭐ VIP Report'; }
    public function headings(): array { return ['VIP Name', 'Priority']; }
    public function map($row): array  { return [$row->name, $row->level]; }
}
```

---

## Output Methods

| Method                                              | Description                   |
| --------------------------------------------------- | ----------------------------- |
| `TurboExcel::stream($export, 'file.xlsx')`           | Stream directly to browser (Fastest, zero-IO) |
| `TurboExcel::download($export, 'file.xlsx')`         | Stream to browser via local temporary file    |
| `TurboExcel::store($export, 'path/file.xlsx', 's3')` | Write to a Storage disk       |
| `TurboExcel::export($export, '/abs/path/file.xlsx')` | Write to filesystem path      |
| `TurboExcel::queue($export, 'path/file.xlsx', 's3')` | Dispatch to a Laravel Queue   |

The export format is auto-detected from the filename extension (`.xlsx` → XLSX, `.csv` → CSV).
Pass an explicit `Format` case to override:

```php
use TurboExcel\Enums\Format;

TurboExcel::download(new UsersExport(), 'users.xlsx', Format::CSV);
```

---

## Format Enum

```php
use TurboExcel\Enums\Format;

Format::XLSX  // application/vnd.openxmlformats-...
Format::CSV   // text/csv
```

---

## Queued Background Exports

When exporting massive amounts of data, you can dispatch the export to a Laravel Queue entirely in the background. Because `TurboExcel` uses `OpenSpout`, memory is kept incredibly flat, handling millions of rows efficiently within a single job.

```php
TurboExcel::queue(new UsersExport(), 'reports/huge-report.xlsx', 's3')
    ->onConnection('redis')
    ->onQueue('exports');
```

**Using with Laravel Batches (Progress Tracking)**

You can also dispatch the export job directly inside a Laravel Batch to track completion percentages! `TurboExcel` automatically breaks your data into chunks and updates the batch progress internally as it writes to the file.

```php
use Illuminate\Support\Facades\Bus;
use TurboExcel\Jobs\ExportJob;

$batch = Bus::batch([
    new ExportJob(new UsersExport(), 'reports/users.xlsx', disk: 's3')
])->name('Daily Users Export')->dispatch();

// Later check progress in your UI
echo $batch->progress(); // e.g. 50%
```

---

## Debugging / Profiling

When dealing with extremely large datasets (millions of rows) in local development, it can be useful to see exactly how much memory the OpenSpout stream is consuming and how fast the database is chunking rows.

Implement the `TurboExcel\Concerns\WithDebug` interface on your export class to automatically emit detailed debugging logs to your default Laravel Log (`storage/logs/laravel.log`).

---

## Testing

```bash
composer test
```

---

## License

MIT
