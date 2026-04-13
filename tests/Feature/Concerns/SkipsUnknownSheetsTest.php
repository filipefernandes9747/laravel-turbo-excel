<?php

declare(strict_types=1);

use TurboExcel\Concerns\FromArray;
use TurboExcel\Enums\Format;
use TurboExcel\Exceptions\UnknownSheetException;
use TurboExcel\Facades\TurboExcel;
use TurboExcel\Import\Concerns\SkipsUnknownSheets;
use TurboExcel\Import\Concerns\ToCollection;
use TurboExcel\Import\Concerns\WithMultipleSheets;

it('throws UnknownSheetException when requesting a nonexistent sheet without SkipsUnknownSheets', function () {
    $path = tmpPath('xlsx');
    file_put_contents($path, ''); // Create empty so we can write to it via export

    // We create a valid XLSX file with 1 sheet using a quick export
    TurboExcel::export(new class implements FromArray
    {
        public function array(): array
        {
            return [['A']];
        }
    }, $path, Format::XLSX);

    $import = new class implements WithMultipleSheets
    {
        public function sheets(): array
        {
            // We request sheet index 5 which doesn't exist
            return [5 => new class implements ToCollection {}];
        }
    };

    expect(fn () => TurboExcel::import($import, $path, format: Format::XLSX))
        ->toThrow(UnknownSheetException::class, 'Sheet with index 5 does not exist in the workbook.');
});

it('gracefully skips nonexistent sheets when SkipsUnknownSheets is implemented', function () {
    $path = tmpPath('xlsx');
    TurboExcel::export(new class implements FromArray
    {
        public function array(): array
        {
            return [['A']];
        }
    }, $path, Format::XLSX);

    $import = new class implements SkipsUnknownSheets, WithMultipleSheets
    {
        public function sheets(): array
        {
            return [5 => new class implements ToCollection {}];
        }
    };

    $result = TurboExcel::import($import, $path, format: Format::XLSX);

    expect($result->processed)->toBe(0)
        ->and($result->failed)->toBe(0)
        ->and($result->rows)->toBeNull();
});
