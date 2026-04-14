<?php

declare(strict_types=1);

namespace TurboExcel\Import\Pipeline;

use Illuminate\Database\Eloquent\Model;
use TurboExcel\Enums\InsertStrategy;
use TurboExcel\Import\Concerns\ToModel;
use TurboExcel\Import\Concerns\WithBatchInserts;
use TurboExcel\Import\Concerns\WithBatchSize;
use TurboExcel\Import\Concerns\WithInsertStrategy;
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

        $batchSize = null;
        if ($this->import instanceof WithBatchInserts) {
            $batchSize = $this->import->batchSize();
        } elseif ($this->import instanceof WithBatchSize) {
            $batchSize = $this->import->batchSize();
        }

        if ($batchSize !== null) {
            $this->buffer[] = $this->attributesForInsert($model);
            if (count($this->buffer) >= $batchSize) {
                $this->flushBuffer();
            }

            return;
        }

        $strategy = $this->import instanceof WithInsertStrategy
            ? $this->import->insertStrategy()
            : InsertStrategy::INSERT;

        if ($strategy === InsertStrategy::UPDATE) {
            $model->exists = true;
            $model->save();

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
                if ($instance->getCreatedAtColumn()) {
                    $this->buffer[$i][$instance->getCreatedAtColumn()] ??= $now;
                }
                if ($instance->getUpdatedAtColumn()) {
                    $this->buffer[$i][$instance->getUpdatedAtColumn()] = $now;
                }
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

        $strategy = $this->import instanceof WithInsertStrategy
            ? $this->import->insertStrategy()
            : ($this->import instanceof WithUpserts ? InsertStrategy::UPSERT : InsertStrategy::INSERT);

        if ($strategy === InsertStrategy::UPSERT) {
            $uniqueBy = $this->import instanceof WithUpserts
                ? (array) $this->import->uniqueBy()
                : (array) $instance->getKeyName();

            $updateColumns = $this->import instanceof WithUpsertColumns
                ? $this->import->upsertColumns()
                : null;

            $instance->newQuery()->upsert($normalized, $uniqueBy, $updateColumns);
        } elseif ($strategy === InsertStrategy::UPDATE) {
            // Update via Upsert is the most efficient batch update in Laravel/SQL
            $uniqueBy = (array) $instance->getKeyName();
            $instance->newQuery()->upsert($normalized, $uniqueBy);
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

