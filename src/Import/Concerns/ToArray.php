<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

use TurboExcel\Import\Result;

/**
 * When implemented without {@see ToModel}, each row that completes the pipeline
 * is appended to {@see Result::$rows} as a plain PHP array instead of a Collection.
 *
 * Combine with {@see WithMapping} to shape each entry. Cannot be used with {@see ShouldQueue}.
 */
interface ToArray {}
