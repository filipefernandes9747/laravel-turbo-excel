<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Output\BufferedOutput;
use TurboExcel\Concerns\FromArray;
use TurboExcel\Concerns\WithHeadings;
use TurboExcel\Concerns\WithMultipleSheets as ExportWithMultipleSheets;
use TurboExcel\Concerns\WithTitle;
use TurboExcel\Enums\Format;
use TurboExcel\Exceptions\TurboExcelException;
use TurboExcel\Facades\TurboExcel;
use TurboExcel\Import\Concerns\OnEachChunk;
use TurboExcel\Import\Concerns\OnEachRow;
use TurboExcel\Import\Concerns\RemembersRowNumber;
use TurboExcel\Import\Concerns\ShouldQueue;
use TurboExcel\Import\Concerns\ShouldQueue as QueueImport;
use TurboExcel\Import\Concerns\SkipsEmptyRows;
use TurboExcel\Import\Concerns\SkipsOnFailure;
use TurboExcel\Import\Concerns\ToCollection;
use TurboExcel\Import\Concerns\ToModel;
use TurboExcel\Import\Concerns\WithBatchInserts;
use TurboExcel\Import\Concerns\WithChunkReading;
use TurboExcel\Import\Concerns\WithHeaderRow;
use TurboExcel\Import\Concerns\WithHeaderValidation;
use TurboExcel\Import\Concerns\WithLimit;
use TurboExcel\Import\Concerns\WithMapping;
use TurboExcel\Import\Concerns\WithMultipleSheets as ImportWithMultipleSheets;
use TurboExcel\Import\Concerns\WithNormalizedHeaders;
use TurboExcel\Import\Concerns\WithProgress;
use TurboExcel\Import\Concerns\WithProgressBar;
use TurboExcel\Import\Concerns\WithStartRow;
use TurboExcel\Import\Concerns\WithUpsertColumns;
use TurboExcel\Import\Concerns\WithUpserts;
use TurboExcel\Import\Concerns\WithValidation;
use TurboExcel\Import\ImportScanner;
use TurboExcel\Import\Readers\CsvReader;
use TurboExcel\Import\Result;
use TurboExcel\Import\SegmentImporter;
use TurboExcel\Import\Traits\Importable;
use TurboExcel\Import\Traits\LogsFailuresToCsv;
use TurboExcel\Import\Traits\RemembersRowNumber as RemembersRowNumberTrait;
use TurboExcel\TurboExcel as TurboExcelService;

beforeEach(function (): void {
    Schema::dropIfExists('import_users');

    Schema::create('import_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->timestamps();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('import_users');
});

class ImportTestUser extends Model
{
    protected $table = 'import_users';

    protected $fillable = ['name', 'email'];
}

class ImportTestSheetAExport implements FromArray, WithTitle
{
    public function array(): array
    {
        return [['product' => 'Widget', 'qty' => 10]];
    }

    public function title(): string
    {
        return 'Inventory';
    }
}

class ImportTestSheetBExport implements FromArray, WithTitle
{
    public function array(): array
    {
        return [['user' => 'Alice', 'role' => 'Admin']];
    }

    public function title(): string
    {
        return 'Users';
    }
}

class ImportTestTwoSheetXlsxExport implements ExportWithMultipleSheets
{
    public function sheets(): array
    {
        return [new ImportTestSheetAExport, new ImportTestSheetBExport];
    }
}

describe('CSV import (sync)', function (): void {
    it('imports rows with header mapping and validation', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "Name,Email\nAlice,alice@example.com\nBob,bob@example.com\n");

        $import = new class implements ToModel, WithHeaderRow, WithMapping, WithNormalizedHeaders, WithValidation
        {
            public function headerRow(): int
            {
                return 1;
            }

            public function headerNormalization(): callable
            {
                return static fn (string $header): string => strtolower(trim($header));
            }

            public function map(array $row): array
            {
                return [
                    'name' => trim($row['name']),
                    'email' => strtolower(trim($row['email'])),
                ];
            }

            public function rules(): array
            {
                return [
                    'email' => 'required|email',
                    'name' => 'required|string',
                ];
            }

            public function model(array $row): ?ImportTestUser
            {
                return new ImportTestUser($row);
            }
        };

        $result = TurboExcel::import($import, $path, format: Format::CSV);

        expect($result)->toBeInstanceOf(Result::class)
            ->and($result->processed)->toBe(2)
            ->and($result->failed)->toBe(0);

        expect(ImportTestUser::query()->count())->toBe(2);
        expect(ImportTestUser::query()->where('email', 'alice@example.com')->exists())->toBeTrue();
    });

    it('strips UTF-8 BOM when present so the first column maps correctly', function (): void {
        $path = tmpPath('csv');
        $bom = "\xEF\xBB\xBF";
        file_put_contents($path, $bom."name,email\nJane,jane@example.com\n");

        $import = new class implements ToModel, WithHeaderRow, WithMapping
        {
            public function headerRow(): int
            {
                return 1;
            }

            public function map(array $row): array
            {
                return ['name' => $row['name'], 'email' => $row['email']];
            }

            public function model(array $row): ?ImportTestUser
            {
                return new ImportTestUser($row);
            }
        };

        $result = app(TurboExcelService::class)->import($import, $path, format: Format::CSV);

        expect($result->processed)->toBe(1);
        expect(ImportTestUser::query()->where('name', 'Jane')->exists())->toBeTrue();
    });

    it('works without BOM for the same layout', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "name,email\nJane,jane2@example.com\n");

        $import = new class implements ToModel, WithHeaderRow, WithMapping
        {
            public function headerRow(): int
            {
                return 1;
            }

            public function map(array $row): array
            {
                return ['name' => $row['name'], 'email' => $row['email']];
            }

            public function model(array $row): ?ImportTestUser
            {
                return new ImportTestUser($row);
            }
        };

        $result = app(TurboExcelService::class)->import($import, $path, format: Format::CSV);

        expect($result->processed)->toBe(1);
        expect(ImportTestUser::query()->where('email', 'jane2@example.com')->exists())->toBeTrue();
    });

    it('normalizes duplicate and empty headers', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "Email,,Email\na@x.com,b@x.com,c@x.com\n");

        $import = new class implements ToModel, WithHeaderRow, WithMapping, WithNormalizedHeaders
        {
            public function headerRow(): int
            {
                return 1;
            }

            public function headerNormalization(): callable
            {
                return static fn (string $header): string => strtolower(trim($header));
            }

            public function map(array $row): array
            {
                return [
                    'first' => $row['email'],
                    'second' => $row['column_2'],
                    'third' => $row['email_1'],
                ];
            }

            public function model(array $row): ?ImportTestUser
            {
                return new ImportTestUser([
                    'name' => $row['first'].'|'.$row['second'].'|'.$row['third'],
                    'email' => 'combined@example.com',
                ]);
            }
        };

        TurboExcel::import($import, $path, format: Format::CSV);

        $u = ImportTestUser::query()->firstOrFail();
        expect($u->name)->toBe('a@x.com|b@x.com|c@x.com');
    });

    it('maps headers using an associative array', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "Email Address,Name Full\njane@example.com,Jane Doe\n");

        $import = new class implements ToModel, WithHeaderRow, WithMapping, WithNormalizedHeaders
        {
            public function headerRow(): int
            {
                return 1;
            }

            public function headerNormalization(): array
            {
                return [
                    'Email Address' => 'email',
                    'Name Full' => 'name',
                ];
            }

            public function map(array $row): array
            {
                return [
                    'email' => trim($row['email']),
                    'name' => trim($row['name']),
                ];
            }

            public function model(array $row): ?ImportTestUser
            {
                return new ImportTestUser($row);
            }
        };

        TurboExcel::import($import, $path, format: Format::CSV);

        $u = ImportTestUser::query()->firstOrFail();
        expect($u->email)->toBe('jane@example.com')
            ->and($u->name)->toBe('Jane Doe');
    });

    it('validates headers when WithHeaderValidation is used', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "wrong,cols\n1,2\n");

        $import = new class implements ToModel, WithHeaderRow, WithHeaderValidation, WithNormalizedHeaders
        {
            public function headerRow(): int
            {
                return 1;
            }

            public function headerNormalization(): callable
            {
                return static fn (string $header): string => strtolower(trim($header));
            }

            public function headers(): array
            {
                return [
                    'email' => 'required',
                    'name' => 'required',
                ];
            }

            public function model(array $row): ?ImportTestUser
            {
                return new ImportTestUser($row);
            }
        };

        TurboExcel::import($import, $path, format: Format::CSV);
    })->throws(ValidationException::class);

    it('skips bad rows when SkipsOnFailure is implemented', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "name,email\nGood,good@example.com\nBad,not-an-email\n");

        $import = new class implements SkipsOnFailure, ToModel, WithHeaderRow, WithMapping, WithValidation
        {
            public array $failures = [];

            public function headerRow(): int
            {
                return 1;
            }

            public function map(array $row): array
            {
                return ['name' => $row['name'], 'email' => $row['email']];
            }

            public function rules(): array
            {
                return ['email' => 'required|email'];
            }

            public function model(array $row): ?ImportTestUser
            {
                return new ImportTestUser($row);
            }

            public function onFailure(array $row, Throwable $e): void
            {
                $this->failures[] = $row;
            }
        };

        $result = TurboExcel::import($import, $path, format: Format::CSV);

        expect($result->processed)->toBe(1)
            ->and($result->failed)->toBe(1)
            ->and(count($import->failures))->toBe(1);

        expect(ImportTestUser::query()->count())->toBe(1);
    });

    it('batch inserts models when WithBatchInserts is used', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "n,e\n1,a1@x.com\n2,a2@x.com\n3,a3@x.com\n");

        $import = new class implements ToModel, WithBatchInserts, WithHeaderRow, WithMapping
        {
            public function headerRow(): int
            {
                return 1;
            }

            public function map(array $row): array
            {
                return ['name' => $row['n'], 'email' => $row['e']];
            }

            public function batchSize(): int
            {
                return 2;
            }

            public function model(array $row): ?ImportTestUser
            {
                return new ImportTestUser($row);
            }
        };

        TurboExcel::import($import, $path, format: Format::CSV);

        expect(ImportTestUser::query()->count())->toBe(3);
    });
});

describe('CsvReader', function (): void {
    it('does not strip BOM when starting after byte zero', function (): void {
        $path = tmpPath('csv');
        $bom = "\xEF\xBB\xBF";
        $body = "a,b\n1,2\n";
        file_put_contents($path, $bom.$body);

        $reader = new CsvReader($path);
        $handle = fopen($path, 'rb');
        CsvReader::skipUtf8BomIfAtStart($handle);
        $start = ftell($handle);
        fclose($handle);

        $rows = iterator_to_array($reader->iterateByByteOffset($start, null));

        expect($rows[0]['cells'])->toBe(['a', 'b'])
            ->and($rows[1]['cells'])->toBe(['1', '2']);
    });
});

describe('ToCollection import', function (): void {
    it('accumulates mapped rows on Result::rows without ToModel', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "a,b\n1,2\n3,4\n");

        $import = new class implements ToCollection, WithHeaderRow, WithMapping
        {
            public function headerRow(): int
            {
                return 1;
            }

            public function map(array $row): array
            {
                return ['x' => $row['a'], 'y' => $row['b']];
            }
        };

        $result = TurboExcel::import($import, $path, format: Format::CSV);

        expect($result->rows)->not->toBeNull()
            ->and($result->rows)->toHaveCount(2)
            ->and($result->rows->first())->toBe(['x' => '1', 'y' => '2'])
            ->and($result->rows->last())->toBe(['x' => '3', 'y' => '4']);
    });

    it('fills the collection when combined with ToModel', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "n,e\nonly,only@example.com\n");

        $import = new class implements ToCollection, ToModel, WithHeaderRow, WithMapping
        {
            public function headerRow(): int
            {
                return 1;
            }

            public function map(array $row): array
            {
                return ['name' => $row['n'], 'email' => $row['e']];
            }

            public function model(array $row): ?ImportTestUser
            {
                return new ImportTestUser($row);
            }
        };

        $result = TurboExcel::import($import, $path, format: Format::CSV);

        expect(ImportTestUser::query()->count())->toBe(1)
            ->and($result->rows)->not->toBeNull()
            ->and($result->rows)->toHaveCount(1)
            ->and($result->rows->first()['email'])->toBe('only@example.com');
    });

    it('rejects ShouldQueue with ToCollection', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "a\n1\n");

        $import = new class implements QueueImport, ToCollection, WithHeaderRow
        {
            public function headerRow(): int
            {
                return 1;
            }
        };

        expect(fn () => TurboExcel::import($import, $path, format: Format::CSV))
            ->toThrow(TurboExcelException::class);
    });
});

describe('Multi-sheet XLSX import', function (): void {
    it('imports each worksheet using its own sheet import', function (): void {
        $path = tmpPath('xlsx');
        TurboExcel::export(new ImportTestTwoSheetXlsxExport, $path, Format::XLSX);

        $master = new class implements ImportWithMultipleSheets
        {
            public function sheets(): array
            {
                return [
                    new class implements ToCollection, WithHeaderRow, WithMapping
                    {
                        public function headerRow(): int
                        {
                            return 1;
                        }

                        public function map(array $row): array
                        {
                            return [
                                'product' => $row['product'],
                                'qty' => (int) $row['qty'],
                            ];
                        }
                    },
                    new class implements ToCollection, WithHeaderRow, WithMapping
                    {
                        public function headerRow(): int
                        {
                            return 1;
                        }

                        public function map(array $row): array
                        {
                            return [
                                'user' => $row['user'],
                                'role' => $row['role'],
                            ];
                        }
                    },
                ];
            }
        };

        $result = TurboExcel::import($master, $path, format: Format::XLSX);

        expect($result->processed)->toBe(2)
            ->and($result->rows)->not->toBeNull()
            ->and($result->rows)->toHaveCount(2)
            ->and($result->rows->get(0)['product'])->toBe('Widget')
            ->and($result->rows->get(1)['user'])->toBe('Alice');
    });

    it('rejects WithMultipleSheets for CSV', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "a\n1\n");

        $master = new class implements ImportWithMultipleSheets
        {
            public function sheets(): array
            {
                return [new stdClass];
            }
        };

        expect(fn () => TurboExcel::import($master, $path, format: Format::CSV))
            ->toThrow(TurboExcelException::class);
    });

    it('rejects row-level concerns on the multi-sheet coordinator', function (): void {
        $path = tmpPath('xlsx');
        TurboExcel::export(new ImportTestTwoSheetXlsxExport, $path, Format::XLSX);

        $master = new class implements ImportWithMultipleSheets, ToModel
        {
            public function sheets(): array
            {
                return [new stdClass];
            }

            public function model(array $row): ?ImportTestUser
            {
                return null;
            }
        };

        expect(fn () => TurboExcel::import($master, $path, format: Format::XLSX))
            ->toThrow(TurboExcelException::class);
    });
});

describe('XLSX import (sync)', function (): void {
    it('round-trips a simple export', function (): void {
        $path = tmpPath('xlsx');

        $export = new class implements FromArray, WithHeadings
        {
            public function array(): array
            {
                return [['Round', 'round@example.com']];
            }

            public function headings(): array
            {
                return ['name', 'email'];
            }
        };

        TurboExcel::export($export, $path, Format::XLSX);

        $import = new class implements ToModel, WithHeaderRow, WithMapping
        {
            public function headerRow(): int
            {
                return 1;
            }

            public function map(array $row): array
            {
                return ['name' => $row['name'], 'email' => $row['email']];
            }

            public function model(array $row): ?ImportTestUser
            {
                return new ImportTestUser($row);
            }
        };

        $result = TurboExcel::import($import, $path, format: Format::XLSX);

        expect($result->processed)->toBe(1);
        expect(ImportTestUser::query()->where('email', 'round@example.com')->exists())->toBeTrue();
    });
});

describe('Queued import', function (): void {
    it('returns Result with zero counts when the file has no data rows', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "h1,h2\n");

        $import = new class implements QueueImport, ToModel, WithChunkReading, WithHeaderRow, WithMapping
        {
            public function chunkSize(): int
            {
                return 1;
            }

            public function headerRow(): int
            {
                return 1;
            }

            public function map(array $row): array
            {
                return $row;
            }

            public function model(array $row): ?ImportTestUser
            {
                return new ImportTestUser($row);
            }
        };

        $result = TurboExcel::import($import, $path, format: Format::CSV);

        expect($result)->toBeInstanceOf(Result::class)
            ->and($result->processed)->toBe(0);
    });
});

describe('Row Callbacks', function (): void {
    it('executes onRow for each mapped and validated row', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "name,email\nAlice,a@x\nBob,b@y\n");

        $import = new class implements OnEachRow, WithHeaderRow, WithMapping
        {
            public array $processedRows = [];

            public function headerRow(): int
            {
                return 1;
            }

            public function map(array $row): array
            {
                return ['name' => strtoupper($row['name']), 'email' => $row['email']];
            }

            public function onRow(array $row): void
            {
                $this->processedRows[] = $row;
            }
        };

        TurboExcel::import($import, $path, format: Format::CSV);

        expect($import->processedRows)->toHaveCount(2)
            ->and($import->processedRows[0])->toBe(['name' => 'ALICE', 'email' => 'a@x'])
            ->and($import->processedRows[1])->toBe(['name' => 'BOB', 'email' => 'b@y']);
    });

    it('executes onChunk accumulating rows up to chunkSize', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "num\n1\n2\n3\n4\n5\n");

        $import = new class implements OnEachChunk, WithChunkReading, WithHeaderRow
        {
            public array $chunks = [];

            public function headerRow(): int
            {
                return 1;
            }

            public function chunkSize(): int
            {
                return 2;
            }

            public function onChunk(Collection $chunk): void
            {
                $this->chunks[] = $chunk->toArray();
            }
        };

        TurboExcel::import($import, $path, format: Format::CSV);

        expect($import->chunks)->toHaveCount(3)
            ->and($import->chunks[0])->toBe([['num' => '1'], ['num' => '2']])
            ->and($import->chunks[1])->toBe([['num' => '3'], ['num' => '4']])
            ->and($import->chunks[2])->toBe([['num' => '5']]);
    });
});

describe('Advanced Features', function (): void {
    it('skips empty rows when SkipsEmptyRows is implemented', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "name,email\nAlice,a@x\n,\nBob,b@y\n\n\n");

        $import = new class implements SkipsEmptyRows, ToModel, WithHeaderRow, WithMapping
        {
            public function headerRow(): int
            {
                return 1;
            }

            public function map(array $row): array
            {
                return ['name' => $row['name'], 'email' => $row['email']];
            }

            public function model(array $row): ?ImportTestUser
            {
                return new ImportTestUser($row);
            }
        };

        $result = TurboExcel::import($import, $path, format: Format::CSV);

        expect($result->processed)->toBe(2);
        expect(ImportTestUser::query()->count())->toBe(2);
    });

    it('upserts models when WithUpserts is used', function (): void {
        Schema::create('import_upsert_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->timestamps();
        });

        $modelClass = new class extends Model
        {
            protected $table = 'import_upsert_users';

            protected $fillable = ['name', 'email'];
        };

        $modelClass::query()->insert([
            ['name' => 'Old Alice', 'email' => 'a@x.com', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $path = tmpPath('csv');
        file_put_contents($path, "name,email\nAlice Updated,a@x.com\nBob,b@x.com\n");

        $import = new class($modelClass) implements ToModel, WithBatchInserts, WithHeaderRow, WithMapping, WithUpsertColumns, WithUpserts
        {
            public function __construct(private Model $modelPrototype) {}

            public function headerRow(): int
            {
                return 1;
            }

            public function map(array $row): array
            {
                return ['name' => $row['name'], 'email' => $row['email']];
            }

            public function batchSize(): int
            {
                return 10;
            }

            public function uniqueBy(): array|string
            {
                return 'email';
            }

            public function upsertColumns(): ?array
            {
                return ['name', 'updated_at'];
            }

            public function model(array $row): ?Model
            {
                $model = clone $this->modelPrototype;
                $model->fill($row);

                return $model;
            }
        };

        TurboExcel::import($import, $path, format: Format::CSV);

        expect($modelClass::query()->count())->toBe(2);
        $alice = $modelClass::query()->where('email', 'a@x.com')->first();
        expect($alice->name)->toBe('Alice Updated');
    });

    it('logs validation failures to a custom csv safely', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "name,email\nAlice,not-an-email\nBob,valid@email.com\nCharlie,also-bad\n");

        $failurePath = tmpPath('csv');

        // Remove file if exists
        if (file_exists($failurePath)) {
            unlink($failurePath);
        }

        $import = new class($failurePath) implements SkipsOnFailure, ToModel, WithHeaderRow, WithMapping, WithValidation
        {
            use LogsFailuresToCsv;

            public function __construct(private string $failurePath) {}

            public function headerRow(): int
            {
                return 1;
            }

            public function map(array $row): array
            {
                return ['name' => $row['name'], 'email' => $row['email']];
            }

            public function rules(): array
            {
                return ['email' => 'required|email'];
            }

            public function model(array $row): ?ImportTestUser
            {
                return new ImportTestUser($row);
            }

            public function failuresExportPath(): string
            {
                return $this->failurePath;
            }
        };

        TurboExcel::import($import, $path, format: Format::CSV);

        expect(ImportTestUser::query()->count())->toBe(1);
        expect(file_exists($failurePath))->toBeTrue();

        $failedCsvContents = trim(file_get_contents($failurePath));
        $lines = explode("\n", str_replace("\r", '', $failedCsvContents));

        expect(count($lines))->toBe(2);
        expect($lines[0])->toContain('Alice', 'not-an-email', 'The email field must be a valid email address.');
        expect($lines[1])->toContain('Charlie', 'also-bad', 'The email field must be a valid email address.');
    });

    it('handles WithStartRow correctly', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "Ignore Me\nAnother Header\nname\nAlice\nBob\n");

        $import = new class implements ToCollection, WithStartRow
        {
            public function startRow(): int
            {
                return 4;
            }
        };

        $result = TurboExcel::import($import, $path, format: Format::CSV);

        expect($result->processed)->toBe(2);
        expect($result->rows[0][0])->toBe('Alice');
        expect($result->rows[1][0])->toBe('Bob');
    });

    it('handles WithLimit correctly', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "num\n1\n2\n3\n4\n5\n");

        $import = new class implements ToCollection, WithHeaderRow, WithLimit
        {
            public function headerRow(): int
            {
                return 1;
            }

            public function limit(): int
            {
                return 2;
            }
        };

        $result = TurboExcel::import($import, $path, format: Format::CSV);

        expect($result->processed)->toBe(2);
        expect($result->rows)->toHaveCount(2);
        expect($result->rows[0]['num'])->toBe('1');
        expect($result->rows[1]['num'])->toBe('2');
    });

    it('works with the Importable trait for fluent API', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "name\nAlice\n");

        $import = new class implements ToCollection, WithHeaderRow
        {
            use Importable;

            public function headerRow(): int
            {
                return 1;
            }
        };

        $result = $import->import($path, format: Format::CSV);

        expect($result->processed)->toBe(1);
        expect($result->rows[0]['name'])->toBe('Alice');
    });

    it('tracks row number with RemembersRowNumber trait', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "A\nB\nC\n");

        $import = new class implements OnEachRow, RemembersRowNumber
        {
            use RemembersRowNumberTrait;

            public array $indices = [];

            public function onRow(array $row): void
            {
                $this->indices[] = $this->getRowNumber();
            }
        };

        TurboExcel::import($import, $path, format: Format::CSV);

        expect($import->indices)->toBe([1, 2, 3]);
    });

    it('works with withMetrics() and WithMetrics interface and returns metrics in Result', function (): void {
        Log::shouldReceive('info')->atLeast()->times(1);

        $path = tmpPath('csv');
        file_put_contents($path, "name\nAlice\n");

        $import = new class implements ToCollection
        {
            use Importable;
        };

        $result = $import->withMetrics()->import($path, format: Format::CSV);

        expect($result->duration)->toBeGreaterThan(0)
            ->and($result->peakMemory)->toBeGreaterThan(0)
            ->and($result->metrics())->toBeArray()
            ->and($result->metrics())->toHaveKeys(['duration', 'peak_memory', 'processed', 'failed', 'rows']);
    });

    it('tracks percentage progress with WithProgress interface', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "A\nB\nC\nD\n");

        $import = new class implements OnEachRow, WithProgress
        {
            public function progressKey(): string
            {
                return 'test-progress';
            }

            public function onRow(array $row): void {}
        };

        Cache::forget('test-progress');

        TurboExcel::import($import, $path, format: Format::CSV);

        // SegmentImporter updates progress at the end of the run
        // In sync mode, it should be 100% after completion
        expect(Cache::get('test-progress'))->toBe(100);
    });

    it('tracks partial percentage for queued imports', function (): void {
        $path = tmpPath('csv');
        // 4 rows, 1 char each + \n
        file_put_contents($path, "a\nb\nc\nd\n");

        $import = new class implements OnEachRow, ShouldQueue, WithChunkReading, WithProgress
        {
            public function progressKey(): string
            {
                return 'queue-progress';
            }

            public function chunkSize(): int
            {
                return 2;
            }

            public function onRow(array $row): void {}
        };

        Cache::forget('queue-progress');

        $scan = (new ImportScanner($import, $path, Format::CSV, 2))->scan();
        $segments = $scan->segments;
        $totalRows = $scan->totalRows;

        $aggregateKey = 'test-agg';
        Cache::put("turbo_excel_import:{$aggregateKey}:processed", 0);

        $importer = new SegmentImporter;

        // Chunk 1
        $importer->run($import, $path, Format::CSV, $segments[0], null, $aggregateKey, $totalRows);
        expect(Cache::get('queue-progress'))->toBe(50);

        // Chunk 2
        $importer->run($import, $path, Format::CSV, $segments[1], null, $aggregateKey, $totalRows);
        expect(Cache::get('queue-progress'))->toBe(100);
    });

    it('tracks progress using WithProgressBar concern (console)', function (): void {
        $path = tmpPath('csv');
        file_put_contents($path, "name\nAlice\nBob\nCharlie\n");

        $import = new class implements ToCollection, WithHeaderRow, WithProgressBar
        {
            use Importable;

            public function headerRow(): int
            {
                return 1;
            }
        };

        $output = new BufferedOutput;
        $import->withProgressBar($output); // This will create a bar internally

        $result = TurboExcel::import($import, $path, format: Format::CSV);

        expect($result->processed)->toBe(3);
        expect($import->getProgressBar()->getProgress())->toBe(3);
        expect($import->getProgressBar()->getMaxSteps())->toBe(3);
    });
});
