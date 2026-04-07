<?php

declare(strict_types=1);

use TurboExcel\Tests\TestCase;

uses(TestCase::class)->in('Feature');

// ---------------------------------------------------------------------------
// Global helpers (available in all test files)
// ---------------------------------------------------------------------------

/**
 * Create a unique temp file path with the given extension.
 * The file is NOT created on disk — just the path is returned.
 */
function tmpPath(string $extension = 'xlsx'): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('turbo-excel-test-', true) . '.' . $extension;
}
