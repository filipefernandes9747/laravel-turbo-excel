<?php

declare(strict_types=1);

namespace FastExcel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Symfony\Component\HttpFoundation\StreamedResponse download(object $export, string $filename, ?\FastExcel\Enums\Format $format = null)
 * @method static string store(object $export, string $path, string $disk = 'local', ?\FastExcel\Enums\Format $format = null)
 * @method static string export(object $export, string $path, ?\FastExcel\Enums\Format $format = null)
 * @method static \Illuminate\Foundation\Bus\PendingDispatch queue(object $export, string $filePath, string $disk = 'local', ?\FastExcel\Enums\Format $format = null)
 *
 * @see \FastExcel\FastExcel
 */
class FastExcel extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \FastExcel\FastExcel::class;
    }
}
