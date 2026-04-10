<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

/**
 * When combined with {@see WithChunkReading}, the import is split into queued chunk jobs.
 * When used alone, a single queued job processes the entire file.
 */
interface ShouldQueue {}
