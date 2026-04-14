<?php

declare(strict_types=1);

namespace TurboExcel\Import\Concerns;

use Illuminate\Validation\ValidationException;

interface SkipsOnValidation
{
    public function onValidationFailed(ValidationException $e): void;
}
