<?php

declare(strict_types=1);

use Illuminate\Database\Query\Builder;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use TurboExcel\Concerns\WithHeadings;
use TurboExcel\Concerns\WithMapping;
use TurboExcel\Concerns\WithQuerySplitBySheet;
use TurboExcel\Concerns\WithTitle;
use TurboExcel\Exceptions\TurboExcelException;
use TurboExcel\Facades\TurboExcel;

function readXlsxSheetsForSplit(string $path): array
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

function tmpPathForSplit(string $extension = 'xlsx'): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('turbo-excel-test-', true).'.'.$extension;
}

describe('WithQuerySplitBySheet', function (): void {
    it('splits a single ordered query into multiple sheets', function (): void {
        $data = collect([
            (object) ['category' => 'A', 'name' => 'Apple'],
            (object) ['category' => 'A', 'name' => 'Apricot'],
            (object) ['category' => 'B', 'name' => 'Banana'],
            (object) ['category' => 'B', 'name' => 'Berry'],
            (object) ['category' => 'C', 'name' => 'Cherry'],
        ]);

        $mockQuery = Mockery::mock(Builder::class);
        $mockQuery->shouldReceive('lazy')->andReturn($data);

        $export = new class($mockQuery) implements WithHeadings, WithQuerySplitBySheet, WithTitle
        {
            private string $currentTitle = '';

            public function __construct(private $mockQuery) {}

            public function query()
            {
                return $this->mockQuery;
            }

            public function splitByColumn(): string
            {
                return 'category';
            }

            public function sheet(mixed $row): object
            {
                $this->currentTitle = 'Category '.$row->category;

                return $this;
            }

            public function title(): string
            {
                return $this->currentTitle;
            }

            public function headings(): array
            {
                return ['ID', 'Category', 'Product Name'];
            }
        };

        $path = tmpPathForSplit();
        TurboExcel::export($export, $path);

        $sheets = readXlsxSheetsForSplit($path);

        expect($sheets)->toHaveCount(3)
            ->and($sheets[0]['name'])->toBe('Category A')
            ->and($sheets[1]['name'])->toBe('Category B')
            ->and($sheets[2]['name'])->toBe('Category C');

        unlink($path);
    });

    it('delegates to separate handler objects for each sheet', function (): void {
        $data = collect([
            (object) ['type' => 'A', 'name' => 'Apple'],
            (object) ['type' => 'B', 'name' => 'Banana'],
        ]);

        $mockQuery = Mockery::mock(Builder::class);
        $mockQuery->shouldReceive('lazy')->andReturn($data);

        // Define two different sheet classes
        $sheetA = new class implements WithHeadings, WithMapping, WithTitle
        {
            public function title(): string
            {
                return 'Title A';
            }

            public function headings(): array
            {
                return ['Header A'];
            }

            public function map($row): array
            {
                return ['Mapped A: '.$row->name];
            }
        };

        $sheetB = new class implements WithHeadings, WithMapping, WithTitle
        {
            public function title(): string
            {
                return 'Title B';
            }

            public function headings(): array
            {
                return ['Header B'];
            }

            public function map($row): array
            {
                return ['Mapped B: '.$row->name];
            }
        };

        $export = new class($mockQuery, $sheetA, $sheetB) implements WithQuerySplitBySheet
        {
            public function __construct(private $mockQuery, private $sheetA, private $sheetB) {}

            public function query()
            {
                return $this->mockQuery;
            }

            public function splitByColumn(): string
            {
                return 'type';
            }

            public function sheet(mixed $row): object
            {
                return $row->type === 'A' ? $this->sheetA : $this->sheetB;
            }
        };

        $path = tmpPathForSplit();
        TurboExcel::export($export, $path);

        $sheets = readXlsxSheetsForSplit($path);

        expect($sheets)->toHaveCount(2)
            ->and($sheets[0]['name'])->toBe('Title A')
            ->and($sheets[0]['rows'][0])->toBe(['Header A'])
            ->and($sheets[0]['rows'][1])->toBe(['Mapped A: Apple'])
            ->and($sheets[1]['name'])->toBe('Title B')
            ->and($sheets[1]['rows'][0])->toBe(['Header B'])
            ->and($sheets[1]['rows'][1])->toBe(['Mapped B: Banana']);

        unlink($path);
    });

    it('throws an exception for CSV format', function (): void {
        $mockQuery = Mockery::mock(Builder::class);

        $export = new class($mockQuery) implements WithQuerySplitBySheet
        {
            public function __construct(private $mockQuery) {}

            public function query()
            {
                return $this->mockQuery;
            }

            public function splitByColumn(): string
            {
                return 'type';
            }

            public function sheet(mixed $row): object
            {
                return $this;
            }
        };

        $path = tmpPathForSplit('csv');

        expect(fn () => TurboExcel::export($export, $path))
            ->toThrow(TurboExcelException::class, 'only supported for XLSX exports');
    });
});
