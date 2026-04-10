<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TurboExcel\Concerns\FromArray;
use TurboExcel\Concerns\FromCollection;
use TurboExcel\Concerns\FromGenerator;
use TurboExcel\Concerns\WithAnonymization;
use TurboExcel\Concerns\WithChunkSize;
use TurboExcel\Concerns\WithHeadings;
use TurboExcel\Concerns\WithMapping;
use TurboExcel\Concerns\WithMultipleSheets;
use TurboExcel\Concerns\WithTitle;
use TurboExcel\Enums\Format;
use TurboExcel\Exceptions\TurboExcelException;
use TurboExcel\TurboExcel;

// ---------------------------------------------------------------------------
// Inline export classes used across tests
// ---------------------------------------------------------------------------

class SimpleArrayExport implements FromArray
{
    public function array(): array
    {
        return [
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'Bob',   'email' => 'bob@example.com'],
        ];
    }
}

class ExplicitHeadingsExport implements FromArray, WithHeadings
{
    public function array(): array
    {
        return [
            ['Alice', 'alice@example.com'],
            ['Bob',   'bob@example.com'],
        ];
    }

    public function headings(): array
    {
        return ['Full Name', 'Email Address'];
    }
}

class MappedExport implements FromArray, WithHeadings, WithMapping
{
    public function array(): array
    {
        return [
            (object) ['first' => 'Alice', 'last' => 'Smith'],
            (object) ['first' => 'Bob',   'last' => 'Jones'],
        ];
    }

    public function headings(): array
    {
        return ['Full Name'];
    }

    public function map(mixed $row): array
    {
        return [$row->first.' '.$row->last];
    }
}

class CollectionExport implements FromCollection
{
    public function collection(): Collection
    {
        return collect([
            ['city' => 'Lisbon',  'country' => 'Portugal'],
            ['city' => 'Madrid',  'country' => 'Spain'],
        ]);
    }
}

class GeneratorExport implements FromGenerator, WithTitle
{
    public function generator(): Generator
    {
        for ($i = 1; $i <= 500; $i++) {
            yield ['id' => $i, 'value' => 'row-'.$i];
        }
    }

    public function title(): string
    {
        return 'Generated Data';
    }
}

class TitledSheet1Export implements FromArray, WithTitle
{
    public function array(): array
    {
        return [['product' => 'Widget', 'qty' => 10]];
    }

    public function title(): string
    {
        return 'Inventory';
    }
}

class TitledSheet2Export implements FromArray, WithTitle
{
    public function array(): array
    {
        return [['user' => 'Alice', 'role' => 'Admin']];
    }

    public function title(): string
    {
        return 'Users';
    }
}

class MultiSheetExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [new TitledSheet1Export, new TitledSheet2Export];
    }
}

class NoSourceExport
{
    // Intentionally implements no data-source concern.
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function readXlsx(string $path): array
{
    $reader = new XlsxReader;
    $reader->open($path);

    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn ($cell) => $cell->getValue(), $row->getCells());
        }
    }

    $reader->close();

    return $rows;
}

function readXlsxSheets(string $path): array
{
    $reader = new XlsxReader;
    $reader->open($path);

    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn ($cell) => $cell->getValue(), $row->getCells());
        }
        $sheets[] = ['name' => $sheet->getName(), 'rows' => $rows];
    }

    $reader->close();

    return $sheets;
}

function readCsv(string $path): array
{
    $reader = new CsvReader;
    $reader->open($path);

    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn ($cell) => $cell->getValue(), $row->getCells());
        }
    }

    $reader->close();

    return $rows;
}

// ---------------------------------------------------------------------------
// Format enum
// ---------------------------------------------------------------------------

describe('Format enum', function (): void {
    it('detects xlsx from filename', fn () => expect(Format::fromFilename('report.xlsx'))->toBe(Format::XLSX)
    );

    it('detects csv from filename', fn () => expect(Format::fromFilename('report.csv'))->toBe(Format::CSV)
    );

    it('defaults to xlsx for unknown extensions', fn () => expect(Format::fromFilename('report.txt'))->toBe(Format::XLSX)
    );

    it('returns the correct MIME type for xlsx', fn () => expect(Format::XLSX->mimeType())
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
    );

    it('returns the correct extension from Enum', function (): void {
        expect(Format::XLSX->extension())->toBe('xlsx')
            ->and(Format::CSV->extension())->toBe('csv');
    });

    it('creates temporary directories if missing', function (): void {
        if (is_dir(storage_path('app/turbo-excel-tmp'))) {
            File::deleteDirectory(storage_path('app/turbo-excel-tmp'));
        }

        $export = new class implements FromArray
        {
            public function array(): array
            {
                return [['key' => 'value']];
            }
        };

        // this triggers `writeTmp()->mkdir`
        $response = \TurboExcel\Facades\TurboExcel::download($export, 'test.csv', Format::CSV);

        ob_start();
        $response->sendContent();
        ob_end_clean();

        expect($response)->toBeInstanceOf(StreamedResponse::class);
        expect(is_dir(storage_path('app/turbo-excel-tmp')))->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// FromArray
// ---------------------------------------------------------------------------

describe('FromArray', function (): void {
    it('exports with auto-derived headings', function (): void {
        $path = tmpPath('xlsx');

        app(TurboExcel::class)->export(new SimpleArrayExport, $path);

        $rows = readXlsx($path);

        expect($rows)
            ->toHaveCount(3)
            ->and($rows[0])->toBe(['name', 'email'])
            ->and($rows[1])->toBe(['Alice', 'alice@example.com'])
            ->and($rows[2])->toBe(['Bob', 'bob@example.com']);

        unlink($path);
    });

    it('exports with explicit WithHeadings', function (): void {
        $path = tmpPath('xlsx');

        app(TurboExcel::class)->export(new ExplicitHeadingsExport, $path);

        $rows = readXlsx($path);

        expect($rows[0])->toBe(['Full Name', 'Email Address'])
            ->and($rows[1])->toBe(['Alice', 'alice@example.com']);

        unlink($path);
    });

    it('applies WithMapping and WithHeadings', function (): void {
        $path = tmpPath('xlsx');

        app(TurboExcel::class)->export(new MappedExport, $path);

        $rows = readXlsx($path);

        expect($rows[0])->toBe(['Full Name'])
            ->and($rows[1])->toBe(['Alice Smith'])
            ->and($rows[2])->toBe(['Bob Jones']);

        unlink($path);
    });
});

// ---------------------------------------------------------------------------
// FromCollection
// ---------------------------------------------------------------------------

describe('FromCollection', function (): void {
    it('exports an Illuminate Collection', function (): void {
        $path = tmpPath('xlsx');

        app(TurboExcel::class)->export(new CollectionExport, $path);

        $rows = readXlsx($path);

        expect($rows[0])->toBe(['city', 'country'])
            ->and($rows[1])->toBe(['Lisbon', 'Portugal'])
            ->and($rows[2])->toBe(['Madrid', 'Spain']);

        unlink($path);
    });
});

// ---------------------------------------------------------------------------
// FromGenerator
// ---------------------------------------------------------------------------

describe('FromGenerator', function (): void {
    it('streams 500 rows without loading all into memory', function (): void {
        $path = tmpPath('xlsx');

        app(TurboExcel::class)->export(new GeneratorExport, $path);

        $rows = readXlsx($path);

        expect($rows)->toHaveCount(501)  // 1 heading + 500 data rows
            ->and($rows[1])->toBe([1, 'row-1'])
            ->and($rows[500])->toBe([500, 'row-500']);

        unlink($path);
    });

    it('respects WithTitle on xlsx export', function (): void {
        $path = tmpPath('xlsx');

        app(TurboExcel::class)->export(new GeneratorExport, $path);

        $sheets = readXlsxSheets($path);

        expect($sheets[0]['name'])->toBe('Generated Data');

        unlink($path);
    });
});

// ---------------------------------------------------------------------------
// WithMultipleSheets
// ---------------------------------------------------------------------------

describe('WithMultipleSheets', function (): void {
    it('writes multiple sheets with correct names and data', function (): void {
        $path = tmpPath('xlsx');

        app(TurboExcel::class)->export(new MultiSheetExport, $path);

        $sheets = readXlsxSheets($path);

        expect($sheets)->toHaveCount(2)
            ->and($sheets[0]['name'])->toBe('Inventory')
            ->and($sheets[0]['rows'][0])->toBe(['product', 'qty'])
            ->and($sheets[0]['rows'][1])->toBe(['Widget', 10])
            ->and($sheets[1]['name'])->toBe('Users')
            ->and($sheets[1]['rows'][0])->toBe(['user', 'role'])
            ->and($sheets[1]['rows'][1])->toBe(['Alice', 'Admin']);

        unlink($path);
    });

    it('throws when multi-sheet export is used with CSV', function (): void {
        $path = tmpPath('csv');

        expect(fn () => app(TurboExcel::class)->export(new MultiSheetExport, $path))
            ->toThrow(TurboExcelException::class, 'CSV does not support multiple sheets');
    });
});

// ---------------------------------------------------------------------------
// CSV
// ---------------------------------------------------------------------------

describe('CSV export', function (): void {
    it('exports FromArray to CSV', function (): void {
        $path = tmpPath('csv');

        app(TurboExcel::class)->export(new SimpleArrayExport, $path);

        $rows = readCsv($path);

        expect($rows[0])->toBe(['name', 'email'])
            ->and($rows[1])->toBe(['Alice', 'alice@example.com']);

        unlink($path);
    });
});

// ---------------------------------------------------------------------------
// Error handling
// ---------------------------------------------------------------------------

describe('Error handling', function (): void {
    it('throws when no data-source concern is implemented', function (): void {
        $path = tmpPath('xlsx');

        expect(fn () => app(TurboExcel::class)->export(new NoSourceExport, $path))
            ->toThrow(TurboExcelException::class, 'FromQuery, FromCollection, FromArray, or FromGenerator');
    });
});

// ---------------------------------------------------------------------------
// download() response headers
// ---------------------------------------------------------------------------

describe('download() response', function (): void {
    it('returns correct Content-Type for xlsx and executes streaming callback', function (): void {
        $response = app(TurboExcel::class)->download(new SimpleArrayExport, 'users.xlsx');

        expect($response->headers->get('Content-Type'))
            ->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->and($response->headers->get('Content-Disposition'))
            ->toContain('users.xlsx');

        // Trigger the callback to hit coverage for the stream output
        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        expect($output)->not->toBeEmpty();
    });

    it('returns correct Content-Type for csv', function (): void {
        $response = app(TurboExcel::class)->download(new SimpleArrayExport, 'users.csv');

        expect($response->headers->get('Content-Type'))
            ->toContain('text/csv')
            ->and($response->headers->get('Content-Disposition'))
            ->toContain('users.csv');
    });
});

// ---------------------------------------------------------------------------
// WithChunkSize (unit-level — no real DB needed)
// ---------------------------------------------------------------------------

describe('WithChunkSize', function (): void {
    it('is read from the export class when implemented', function (): void {
        $export = new class implements FromArray, WithChunkSize
        {
            public function array(): array
            {
                return [['x' => 1]];
            }

            public function chunkSize(): int
            {
                return 250;
            }
        };

        // Just verify no exception is thrown and it exports successfully.
        $path = tmpPath('xlsx');
        app(TurboExcel::class)->export($export, $path);
        expect(file_exists($path))->toBeTrue();
        unlink($path);
    });
});

// ---------------------------------------------------------------------------
// Normalisation
// ---------------------------------------------------------------------------

describe('Row normalisation', function (): void {
    it('normalises JsonSerializable objects', function (): void {
        $export = new class implements FromArray
        {
            public function array(): array
            {
                return [
                    new class implements JsonSerializable
                    {
                        public function jsonSerialize(): mixed
                        {
                            return ['json' => 'serialised'];
                        }
                    },
                ];
            }
        };

        $path = tmpPath('xlsx');
        app(TurboExcel::class)->export($export, $path);

        $rows = readXlsx($path);
        expect($rows[1])->toBe(['serialised']);

        unlink($path);
    });

    it('normalises generic objects', function (): void {
        $export = new class implements FromArray
        {
            public function array(): array
            {
                $obj = new stdClass;
                $obj->generic = 'object';

                return [$obj];
            }
        };

        $path = tmpPath('xlsx');
        app(TurboExcel::class)->export($export, $path);

        $rows = readXlsx($path);
        expect($rows[1])->toBe(['object']);

        unlink($path);
    });

    it('normalises scalar values', function (): void {
        $export = new class implements FromArray
        {
            public function array(): array
            {
                return ['scalar'];
            }
        };

        $path = tmpPath('xlsx');
        app(TurboExcel::class)->export($export, $path);

        $rows = readXlsx($path);
        expect($rows[1])->toBe(['scalar']);

        unlink($path);
    });
});

// ---------------------------------------------------------------------------
// Output methods
// ---------------------------------------------------------------------------

describe('Output methods coverage', function (): void {
    it('can store() to a disk', function (): void {
        Storage::fake('local');

        $path = app(TurboExcel::class)->store(new SimpleArrayExport, 'stored.xlsx');

        expect($path)->toBe('stored.xlsx');
        Storage::disk('local')->assertExists('stored.xlsx');
    });

    it('can export() directly to filesystem', function (): void {
        $path = tmpPath('xlsx');
        $returnedPath = app(TurboExcel::class)->export(new SimpleArrayExport, $path);

        expect($returnedPath)->toBe($path)
            ->and(file_exists($path))->toBeTrue();

        unlink($path);
    });

    it('can use Facade properly', function (): void {
        $path = tmpPath('xlsx');
        \TurboExcel\Facades\TurboExcel::export(new SimpleArrayExport, $path);
        expect(file_exists($path))->toBeTrue();
        unlink($path);
    });

    it('can stream() directly to output without touching disk', function (): void {
        $export = new class implements FromArray
        {
            public function array(): array
            {
                return [['key' => 'direct-stream']];
            }
        };

        $response = \TurboExcel\Facades\TurboExcel::stream($export, 'stream.csv', Format::CSV);

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        expect($response)->toBeInstanceOf(StreamedResponse::class)
            ->and($content)->toContain('direct-stream');
    });
});

// ---------------------------------------------------------------------------
// Deprecations and Writers
// ---------------------------------------------------------------------------

describe('Concern: WithAnonymization', function (): void {
    it('anonymizes specified columns by default', function (): void {
        $export = new class implements FromArray, WithAnonymization
        {
            public function array(): array
            {
                return [
                    ['name' => 'John Doe', 'email' => 'john@example.com', 'id' => 1],
                ];
            }

            public function anonymizeColumns(): array
            {
                return ['name', 'email'];
            }

            public function anonymizeReplacement(): string
            {
                return '[HIDDEN]';
            }
        };

        $path = tmpPath('xlsx');
        \TurboExcel\Facades\TurboExcel::export($export, $path);

        $data = readXlsx($path);

        expect($data[1][0])->toBe('[HIDDEN]')
            ->and($data[1][1])->toBe('[HIDDEN]');

        unlink($path);
    });

    it('can explicitly disable anonymization via method', function (): void {
        $export = new class implements FromArray, WithAnonymization
        {
            public function array(): array
            {
                return [
                    ['name' => 'John Doe', 'email' => 'john@example.com', 'id' => 1],
                ];
            }

            public function isAnonymizationEnabled(): bool
            {
                return false;
            }

            public function anonymizeColumns(): array
            {
                return ['name', 'email'];
            }

            public function anonymizeReplacement(): string
            {
                return '[HIDDEN]';
            }
        };

        $path = tmpPath('xlsx');
        \TurboExcel\Facades\TurboExcel::export($export, $path);

        $data = readXlsx($path);

        expect($data[1][0])->toBe('John Doe')
            ->and($data[1][1])->toBe('john@example.com');

        unlink($path);
    });
});
