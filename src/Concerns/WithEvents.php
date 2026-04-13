<?php

declare(strict_types=1);

namespace TurboExcel\Concerns;

interface WithEvents
{
    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array;
}
