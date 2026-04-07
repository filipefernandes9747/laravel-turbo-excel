<?php

declare(strict_types=1);

namespace FastExcel\Tests;

use FastExcel\FastExcelServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [FastExcelServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'FastExcel' => \FastExcel\Facades\FastExcel::class,
        ];
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Create a unique temp file path with the given extension.
     * The file is NOT created on disk — only the path is returned.
     */
    protected function tmpPath(string $extension = 'xlsx'): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('fast-excel-test-', true) . '.' . $extension;
    }
}
