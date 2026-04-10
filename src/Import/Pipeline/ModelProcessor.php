<?php

declare(strict_types=1);

namespace TurboExcel\Import\Pipeline;

use Illuminate\Database\Eloquent\Model;
use TurboExcel\Import\Concerns\ToModel;
use TurboExcel\Import\Concerns\WithBatchInserts;
use TurboExcel\Import\Concerns\WithUpsertColumns;
use TurboExcel\Import\Concerns\WithUpserts;

final class ModelProcessor
{
    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private ?string $modelClass = null;

    public function __construct(private readonly object $import) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public function persist(array $row): void
    {
        if (! $this->import instanceof ToModel) {
            return;
        }

        $model = $this->import->model($row);
        if ($model === null) {
            return;
        }

        $this->modelClass ??= $model::class;

        if ($this->import instanceof WithBatchInserts) {
            $this->buffer[] = $this->attributesForInsert($model);
            if (count($this->buffer) >= $this->import->batchSize()) {
                $this->flushBuffer();
            }

            return;
        }

        $model->save();
    }

    public function flush(): void
    {
        if ($this->buffer !== []) {
            $this->flushBuffer();
        }
    }

    private function flushBuffer(): void
    {
        if ($this->buffer === [] || $this->modelClass === null) {
            return;
        }

        /** @var class-string<Model> $class */
        $class = $this->modelClass;
        /** @var Model $instance */
        $instance = new $class;

        if ($instance->usesTimestamps()) {
            $now = $instance->freshTimestampString();
            foreach ($this->buffer as $i => $attrs) {
                $this->buffer[$i][$instance->getCreatedAtColumn()] = $now;
                $this->buffer[$i][$instance->getUpdatedAtColumn()] = $now;
            }
        }

        $columns = array_keys($this->buffer[0]);
        $normalized = [];
        foreach ($this->buffer as $attrs) {
            $row = [];
            foreach ($columns as $col) {
                $row[$col] = $attrs[$col] ?? null;
            }
            $normalized[] = $row;
        }

        if ($this->import instanceof WithUpserts) {
            $uniqueBy = (array) $this->import->uniqueBy();
            $updateColumns = $this->import instanceof WithUpsertColumns 
                ? $this->import->upsertColumns() 
                : null;
            
            $instance->newQuery()->upsert($normalized, $uniqueBy, $updateColumns);
        } else {
            $instance->newQuery()->insert($normalized);
        }
        $this->buffer = [];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesForInsert(Model $model): array
    {
        return $model->getAttributes();
    }
}
