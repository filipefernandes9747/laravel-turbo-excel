<?php

declare(strict_types=1);

namespace TurboExcel\Tests;

use TurboExcel\TurboExcelServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [TurboExcelServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'TurboExcel' => \TurboExcel\Facades\TurboExcel::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
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
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('turbo-excel-test-', true) . '.' . $extension;
    }
}
