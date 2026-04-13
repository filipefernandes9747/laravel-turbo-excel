<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

/**
 * When implemented, if a requested sheet is not found in the file,
 * the importer will gracefully skip it instead of throwing an UnknownSheetException.
 */
interface SkipsUnknownSheets {}
