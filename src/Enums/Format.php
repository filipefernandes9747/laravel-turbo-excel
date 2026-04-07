<?php

declare(strict_types=1);

namespace TurboExcel\Enums;

enum Format: string
{
    case XLSX = 'xlsx';
    case CSV  = 'csv';

    // ---------------------------------------------------------------------------
    // Derived properties
    // ---------------------------------------------------------------------------

    public function mimeType(): string
    {
        return match ($this) {
            self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::CSV  => 'text/csv',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }

    // ---------------------------------------------------------------------------
    // Factory helpers
    // ---------------------------------------------------------------------------

    public static function fromExtension(string $extension): self
    {
        return match (strtolower($extension)) {
            'csv'  => self::CSV,
            default => self::XLSX,
        };
    }

    public static function fromFilename(string $filename): self
    {
        return self::fromExtension(pathinfo($filename, PATHINFO_EXTENSION));
    }
}
