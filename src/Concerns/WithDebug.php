<?php

declare(strict_types=1);

namespace FastExcel\Concerns;

/**
 * Marker interface to enable debug logging during export.
 * Logs execution time, chunk processing, and peak memory usage to Laravel's default log channel.
 */
interface WithDebug
{
}
