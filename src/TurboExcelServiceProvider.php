<?php

declare(strict_types=1);

namespace TurboExcel;

use Illuminate\Support\ServiceProvider;

class TurboExcelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind as a singleton so the Facade always resolves the same instance.
        $this->app->singleton(TurboExcel::class, fn () => new TurboExcel());
    }

    public function boot(): void
    {
        // No publishable assets or configs needed.
    }
}
