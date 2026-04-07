<?php

declare(strict_types=1);

namespace FastExcel;

use Illuminate\Support\ServiceProvider;

class FastExcelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind as a singleton so the Facade always resolves the same instance.
        $this->app->singleton(FastExcel::class, fn () => new FastExcel());
    }

    public function boot(): void
    {
        // No publishable assets or configs needed.
    }
}
