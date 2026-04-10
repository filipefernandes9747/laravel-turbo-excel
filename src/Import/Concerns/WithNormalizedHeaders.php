<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

interface WithNormalizedHeaders
{
    /**
     * How to turn each trimmed header cell into an attribute key, before duplicate handling (`name`, `name_1`, …)
     * and empty-header fallbacks (`column_1`, …).
     *
     * Return either:
     * - **Associative map:** keys are the trimmed header text as it appears in the file (e.g. `'Email Address'`, `'FULL NAME'`),
     *   values are the desired keys (e.g. `'email'`, `'name'`). Unlisted headers keep the trimmed string as the key.
     * - **Callable:** `fn (string $header): string` receives the trimmed header and must return the key string.
     */
    public function headerNormalization(): array|callable;
}
