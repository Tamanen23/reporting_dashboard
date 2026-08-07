<?php

namespace App\Providers;

use App\Domain\Reports\Contracts\ReportDefinitionRegistry;
use App\Domain\Reports\Models\ReportGeneration;
use App\Domain\Reports\Services\DatabaseReportDefinitionRegistry;
use App\Policies\ReportGenerationPolicy;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(ReportGeneration::class, ReportGenerationPolicy::class);
    }
}
