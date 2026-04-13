<?php

declare(strict_types=1);

namespace TurboExcel\Import\Traits;

use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use TurboExcel\Enums\Format;
use TurboExcel\Facades\TurboExcel;
use TurboExcel\Import\Concerns\WithMetrics;
use TurboExcel\Import\Result;

trait Importable
{
    private bool $withMetrics = false;

    protected ?ProgressBar $progressBar = null;

    public function import(string $path, ?Format $format = null): Result|Batch
    {
        return TurboExcel::import($this, $path, format: $format);
    }

    public function queue(string $path, ?Format $format = null): Batch
    {
        // Internal check to ensure it returns a Batch
        return TurboExcel::import($this, $path, format: $format);
    }

    public function withMetrics(): self
    {
        $this->withMetrics = true;

        return $this;
    }

    public function isMetricsEnabled(): bool
    {
        return $this->withMetrics || $this instanceof WithMetrics;
    }

    public function withProgressBar(mixed $output): self
    {
        if ($output instanceof ProgressBar) {
            $this->progressBar = $output;
        } elseif ($output instanceof Command) {
            $this->progressBar = $output->getOutput()->createProgressBar();
        } elseif ($output instanceof OutputInterface) {
            $this->progressBar = new ProgressBar($output);
        }

        return $this;
    }

    public function getProgressBar(): ?ProgressBar
    {
        return $this->progressBar;
    }
}
