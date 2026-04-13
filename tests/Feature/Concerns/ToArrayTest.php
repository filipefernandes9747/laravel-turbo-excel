<?php

declare(strict_types=1);

use TurboExcel\Concerns\FromArray;
use TurboExcel\Concerns\WithTitle;
use TurboExcel\Enums\Format;
use TurboExcel\Facades\TurboExcel;
use TurboExcel\Import\Concerns\ToArray;
use TurboExcel\Import\Concerns\WithHeaderRow;
use TurboExcel\Import\Concerns\WithMapping;
use TurboExcel\Import\Concerns\WithMultipleSheets;

it('accumulates mapped rows on Result::rows as an array without ToModel', function (): void {
    $path = tmpPath('csv');
    file_put_contents($path, "a,b\n1,2\n3,4\n");

    $import = new class implements ToArray, WithHeaderRow, WithMapping
    {
        public function headerRow(): int
        {
            return 1;
        }

        public function map(array $row): array
        {
            return ['x' => $row['a'] ?? null, 'y' => $row['b'] ?? null];
        }
    };

    $result = TurboExcel::import($import, $path, format: Format::CSV);

    expect($result->rows)->toBeArray()
        ->and($result->rows)->toHaveCount(2)
        ->and($result->rows[0])->toBe(['x' => '1', 'y' => '2'])
        ->and($result->rows[1])->toBe(['x' => '3', 'y' => '4']);
});

it('merges multiple sheets arrays correctly when using ToArray', function (): void {
    $path = tmpPath('xlsx');
    TurboExcel::export(new class implements FromArray
    {
        public function array(): array
        {
            return [['A'], ['B']];
        }
    }, $path, Format::XLSX);

    $import = new class implements WithMultipleSheets
    {
        public function sheets(): array
        {
            return [
                0 => new class implements ToArray, WithHeaderRow
                {
                    public function headerRow(): int
                    {
                        return 1;
                    }
                },
                1 => new class implements ToArray, WithHeaderRow
                {
                    public function headerRow(): int
                    {
                        return 1;
                    }
                },
            ];
        }
    };

    // To properly test the array merge, we will use an array with 2 sheets
    $pathMulti = tmpPath('xlsx');
    $masterExport = new class implements \TurboExcel\Concerns\WithMultipleSheets
    {
        public function sheets(): array
        {
            return [
                new class implements FromArray, WithTitle
                {
                    public function title(): string
                    {
                        return 'Sheet A';
                    }

                    public function array(): array
                    {
                        return [['Sheet1Row1']];
                    }
                },
                new class implements FromArray, WithTitle
                {
                    public function title(): string
                    {
                        return 'Sheet B';
                    }

                    public function array(): array
                    {
                        return [['Sheet2Row1'], ['Sheet2Row2']];
                    }
                },
            ];
        }
    };
    TurboExcel::export($masterExport, $pathMulti, Format::XLSX);

    $result = TurboExcel::import($import, $pathMulti, format: Format::XLSX);

    expect($result->rows)->toBeArray()
        ->and($result->rows)->toHaveCount(3)
        ->and($result->rows[0]['0'])->toBe('Sheet1Row1')
        ->and($result->rows[1]['0'])->toBe('Sheet2Row1')
        ->and($result->rows[2]['0'])->toBe('Sheet2Row2');
});
