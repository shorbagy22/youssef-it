<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\LLMClient;
use App\Services\OllamaClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LLMClient::class, OllamaClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
