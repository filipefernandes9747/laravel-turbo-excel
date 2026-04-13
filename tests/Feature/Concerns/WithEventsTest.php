<?php

declare(strict_types=1);

use TurboExcel\Concerns\FromArray;
use TurboExcel\Concerns\WithEvents;
use TurboExcel\Enums\Format;
use TurboExcel\Events\AfterExport;
use TurboExcel\Events\AfterImport;
use TurboExcel\Events\BeforeExport;
use TurboExcel\Events\BeforeImport;
use TurboExcel\Facades\TurboExcel;
use TurboExcel\Import\Concerns\ToArray;
use TurboExcel\Import\Result;

it('dispatches BeforeExport and AfterExport events', function (): void {
    $path = tmpPath('csv');

    $export = new class implements FromArray, WithEvents
    {
        public int $beforeFired = 0;

        public int $afterFired = 0;

        public function array(): array
        {
            return [['Data']];
        }

        public function registerEvents(): array
        {
            return [
                BeforeExport::class => function (BeforeExport $event) {
                    $this->beforeFired++;
                    expect($event->export)->toBe($this);
                },
                AfterExport::class => function (AfterExport $event) {
                    $this->afterFired++;
                    expect($event->export)->toBe($this);
                },
            ];
        }
    };

    TurboExcel::export($export, $path, Format::CSV);

    expect($export->beforeFired)->toBe(1)
        ->and($export->afterFired)->toBe(1);
});

it('dispatches BeforeImport and AfterImport events', function (): void {
    $path = tmpPath('csv');
    file_put_contents($path, "a,b\n1,2\n");

    $import = new class implements ToArray, WithEvents
    {
        public int $beforeFired = 0;

        public int $afterFired = 0;

        public ?Result $capturedResult = null;

        public function registerEvents(): array
        {
            return [
                BeforeImport::class => function (BeforeImport $event) {
                    $this->beforeFired++;
                    expect($event->import)->toBe($this);
                },
                AfterImport::class => function (AfterImport $event) {
                    $this->afterFired++;
                    $this->capturedResult = $event->result;
                },
            ];
        }
    };

    TurboExcel::import($import, $path, format: Format::CSV);

    expect($import->beforeFired)->toBe(1)
        ->and($import->afterFired)->toBe(1)
        ->and($import->capturedResult)->not->toBeNull()
        ->and($import->capturedResult->processed)->toBe(2);
});
