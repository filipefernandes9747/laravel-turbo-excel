<?php

declare(strict_types=1);

namespace TurboExcel\Import\Pipeline;

use Illuminate\Support\Facades\Validator;
use TurboExcel\Import\Concerns\WithHeaderValidation;
use TurboExcel\Import\Concerns\WithNormalizedHeaders;

final class HeaderProcessor
{
    /**
     * @param  list<string>  $cells
     * @return list<string> column keys in order (for array_combine)
     */
    public static function buildHeaderKeys(array $cells, object $import): array
    {
        $keys = [];
        $seen = [];

        foreach ($cells as $i => $raw) {
            $label = trim((string) $raw);

            if ($import instanceof WithNormalizedHeaders) {
                $label = self::applyHeaderNormalization($label, $import);
            }

            if ($label === '') {
                $label = 'column_'.($i + 1);
            }

            $base = $label;
            $count = $seen[$base] ?? 0;
            if ($count > 0) {
                $label = $base.'_'.$count;
            }
            $seen[$base] = $count + 1;

            $keys[] = $label;
        }

        return $keys;
    }

    private static function applyHeaderNormalization(string $trimmed, WithNormalizedHeaders $import): string
    {
        $normalization = $import->headerNormalization();

        if (is_array($normalization)) {
            return $normalization[$trimmed] ?? $trimmed;
        }

        return $normalization($trimmed);
    }

    /**
     * @param  list<string>  $cells
     * @return array<string, string> normalized key => original trimmed cell (for validation)
     */
    public static function headerKeyToDisplay(array $cells, object $import): array
    {
        $keys = self::buildHeaderKeys($cells, $import);
        $map = [];
        foreach ($keys as $i => $key) {
            $map[$key] = trim((string) ($cells[$i] ?? ''));
        }

        return $map;
    }

    /**
     * @param  list<string>  $cells
     */
    public static function validateHeaders(array $cells, object $import): void
    {
        if (! $import instanceof WithHeaderValidation) {
            return;
        }

        $data = self::headerKeyToDisplay($cells, $import);

        Validator::make($data, $import->headers())->validate();
    }
}
