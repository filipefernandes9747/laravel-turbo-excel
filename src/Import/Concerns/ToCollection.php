<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

use TurboExcel\Import\Result;

/**
 * When implemented without {@see ToModel}, each row that completes the pipeline (map + validation)
 * is appended to {@see Result::$rows} as an associative array.
 *
 * Combine with {@see WithMapping} to shape each entry. Cannot be used with {@see ShouldQueue}.
 */
interface ToCollection {}
