<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

interface WithAnonymization
{
    /**
     * Return the column keys (e.g. ['email', 'phone']) that should be anonymized.
     */
    public function anonymizeColumns(): array;

    /**
     * The value to replace the sensitive data with.
     */
    public function anonymizeReplacement(): string;
}
