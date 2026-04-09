<?php

declare(strict_types=1);

use TurboExcel\Concerns\Exportable;
use TurboExcel\Concerns\FromArray;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Foundation\Bus\PendingDispatch;

it('can download using the trait', function (): void {
    $export = new class implements FromArray {
        use Exportable;
        public function array(): array { return [['data' => 1]]; }
    };
    
    $response = $export->download('users.xlsx');

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('Content-Disposition'))->toContain('users.xlsx');
});

it('can store using the trait', function (): void {
    Storage::fake('local');
    
    $export = new class implements FromArray {
        use Exportable;
        public function array(): array { return [['data' => 1]]; }
    };
    
    $path = $export->store('exported_users.xlsx');

    expect($path)->toBe('exported_users.xlsx');
    Storage::disk('local')->assertExists('exported_users.xlsx');
});

it('can queue using the trait', function (): void {
    Queue::fake();

    $export = new class implements FromArray {
        use Exportable;
        public function array(): array { return [['data' => 1]]; }
    };
    
    $pendingDispatch = $export->queue('queued_users.xlsx');

    expect($pendingDispatch)->toBeInstanceOf(PendingDispatch::class);
});

it('can stream using the trait', function (): void {
    $export = new class implements FromArray {
        use Exportable;
        public function array(): array { return [['data' => 1]]; }
    };
    
    $response = $export->stream('streamed_users.csv');

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('Content-Type'))->toContain('text/csv');
});

it('can export using the trait', function (): void {
    $path = tmpPath('xlsx');
    
    $export = new class implements FromArray {
        use Exportable;
        public function array(): array { return [['data' => 1]]; }
    };
    
    $returnedPath = $export->export($path);

    expect($returnedPath)->toBe($path)
        ->and(file_exists($path))->toBeTrue();

    unlink($path);
});
