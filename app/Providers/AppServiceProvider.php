<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ExcelFileProvider;
use App\Contracts\LLMClient;
use App\Services\OllamaClient;
use App\Services\SharePointExcelService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LLMClient::class, OllamaClient::class);
        $this->app->bind(ExcelFileProvider::class, SharePointExcelService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
