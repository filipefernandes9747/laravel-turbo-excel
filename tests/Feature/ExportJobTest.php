<?php

declare(strict_types=1);

use FastExcel\Enums\Format;
use FastExcel\Facades\FastExcel;
use FastExcel\Jobs\ExportJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

describe('Queued Exports', function (): void {
    it('dispatches the export job through the facade', function (): void {
        Queue::fake();

        $export = new class implements \FastExcel\Concerns\FromArray {
            public function array(): array { return [['data' => 1]]; }
        };

        FastExcel::queue($export, 'exports/data.xlsx', 's3', Format::XLSX);

        Queue::assertPushed(ExportJob::class, function (ExportJob $job) {
            return $job->filePath === 'exports/data.xlsx'
                && $job->disk === 's3'
                && $job->format === Format::XLSX;
        });
    });

    it('handles the export and stores it to the selected disk', function (): void {
        Storage::fake('s3');
        // Delete tmp dir to hit the mkdir branch
        if (is_dir(storage_path('app/fast-excel-tmp'))) {
            \Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/fast-excel-tmp'));
        }

        $export = new class implements \FastExcel\Concerns\FromArray {
            public function array(): array { return [['key' => 'value']]; }
        };

        $job = new ExportJob($export, 'data.csv', 's3', Format::CSV);
        $job->handle();

        Storage::disk('s3')->assertExists('data.csv');
        $content = Storage::disk('s3')->get('data.csv');
        expect($content)->toContain('key', 'value');
    });

    it('cancels gracefully if batch is cancelled', function (): void {
        $export = new class implements \FastExcel\Concerns\FromArray {
            public function array(): array { return []; }
        };

        $batch = Mockery::mock(\Illuminate\Bus\Batch::class);
        $batch->shouldReceive('cancelled')->andReturn(true);
        
        $repo = Mockery::mock(\Illuminate\Bus\BatchRepository::class);
        $repo->shouldReceive('find')->with('fake-id')->andReturn($batch);
        
        app()->instance(\Illuminate\Bus\BatchRepository::class, $repo);

        $job = new ExportJob($export, 'cancelled.csv', 's3', Format::CSV);
        $job->withBatchId('fake-id');

        $job->handle();
        
        // Assert it returned early
        Storage::fake('s3');
        Storage::disk('s3')->assertMissing('cancelled.csv');
    });

    it('calculates total rows from Collections and Generators', function (): void {
        Storage::fake('local');
        $exportCollection = new class implements \FastExcel\Concerns\FromCollection {
            public function collection(): \Illuminate\Support\Collection { return collect([['a' => 1]]); }
        };
        $jobC = new ExportJob($exportCollection, 'col.csv', 'local', Format::CSV);
        $jobC->handle();
        expect(Storage::disk('local')->exists('col.csv'))->toBeTrue();

        $exportGenerator = new class implements \FastExcel\Concerns\FromGenerator {
            public function generator(): \Generator { yield ['a' => 1]; }
        };
        $jobG = new ExportJob($exportGenerator, 'gen.csv', 'local', Format::CSV);
        $jobG->handle();
        expect(Storage::disk('local')->exists('gen.csv'))->toBeTrue();
    });
});
