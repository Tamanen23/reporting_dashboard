<?php

namespace App\Providers;

use App\Domain\Reports\Contracts\ReportDefinitionRegistry;
use App\Domain\Reports\Services\DatabaseReportDefinitionRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ReportDefinitionRegistry::class, DatabaseReportDefinitionRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
