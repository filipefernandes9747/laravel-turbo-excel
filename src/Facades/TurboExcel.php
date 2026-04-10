<?php

declare(strict_types=1);

namespace TurboExcel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Symfony\Component\HttpFoundation\StreamedResponse download(object $export, string $filename, ?\TurboExcel\Enums\Format $format = null)
 * @method static \Symfony\Component\HttpFoundation\StreamedResponse stream(object $export, string $filename, ?\TurboExcel\Enums\Format $format = null)
 * @method static \Illuminate\Foundation\Bus\PendingDispatch queue(object $export, string $filePath, string $disk = 'local', ?\TurboExcel\Enums\Format $format = null)
 * @method static string store(object $export, string $path, string $disk = 'local', ?\TurboExcel\Enums\Format $format = null)
 * @method static string export(object $export, string $path, ?\TurboExcel\Enums\Format $format = null)
 * @method static \TurboExcel\Import\Result|\Illuminate\Bus\Batch import(object $import, string $path, ?\TurboExcel\Enums\Format $format = null)
 *
 * @see \TurboExcel\TurboExcel
 */
class TurboExcel extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \TurboExcel\TurboExcel::class;
    }
}
