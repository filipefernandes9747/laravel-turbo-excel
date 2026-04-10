<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use TurboExcel\Concerns\FromArray;
use TurboExcel\Concerns\WithHeadings;
use TurboExcel\Concerns\WithQuerySplitBySheet;
use TurboExcel\TurboExcel;

// ---------------------------------------------------------------------------
// Inline export classes for testing
// ---------------------------------------------------------------------------

class EmptyArrayWithHeadingsExport implements FromArray, WithHeadings
{
    public function array(): array
    {
        return [];
    }

    public function headings(): array
    {
        return ['Column A', 'Column B'];
    }
}

class EmptySplitSheetExport implements WithHeadings, WithQuerySplitBySheet
{
    public function query()
    {
        return DB::table('users')->where('id', '<', 0)->orderBy('id');
    }

    public function splitByColumn(): string
    {
        return 'department';
    }

    public function sheet(mixed $row): object
    {
        return $this;
    }

    public function headings(): array
    {
        return ['Name', 'Email', 'Department'];
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function getXlsxRows(string $path): array
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

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('Empty Export Handling', function (): void {

    it('writes headings even when FromArray returns no data', function (): void {
        $path = tmpPath('xlsx');
        app(TurboExcel::class)->export(new EmptyArrayWithHeadingsExport, $path);

        $rows = getXlsxRows($path);

        expect($rows)->toHaveCount(1)
            ->and($rows[0])->toBe(['Column A', 'Column B']);

        unlink($path);
    });

    it('creates at least one valid sheet with headings for empty SplitSheet exports', function (): void {
        // Prepare missing table
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('department');
        });

        $path = tmpPath('xlsx');
        app(TurboExcel::class)->export(new EmptySplitSheetExport, $path);

        $rows = getXlsxRows($path);

        // Should have at least one sheet (Sheet 1) and the headings.
        expect($rows)->toHaveCount(1)
            ->and($rows[0])->toBe(['Name', 'Email', 'Department']);

        unlink($path);
        Schema::drop('users');
    });

    it('works correctly for empty FromArray WITHOUT headings (results in 0 rows)', function (): void {
        $export = new class implements FromArray
        {
            public function array(): array
            {
                return [];
            }
        };

        $path = tmpPath('xlsx');
        app(TurboExcel::class)->export($export, $path);

        $rows = getXlsxRows($path);

        // Without headings and without data, we can't derive anything.
        // It should still be a valid file with at least one empty safeguard row to avoid Excel corruption.
        expect($rows)->toHaveCount(1);

        unlink($path);
    });
});
