<?php

declare(strict_types=1);

namespace TurboExcel\Import\Pipeline;

use Illuminate\Support\Facades\Validator;
use TurboExcel\Import\Concerns\WithValidation;

final class RowValidator
{
    /**
     * @param  array<string, mixed>  $row
     */
    public static function validate(object $import, array $row): void
    {
        if (! $import instanceof WithValidation) {
            return;
        }

        Validator::make($row, $import->rules())->validate();
    }
}
