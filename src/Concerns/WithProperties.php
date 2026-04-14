<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

interface WithProperties
{
    /**
     * @return array{
     *     title?: string,
     *     subject?: string,
     *     creator?: string,
     *     keywords?: string,
     *     description?: string,
     *     category?: string
     * }
     */
    public function properties(): array;
}
