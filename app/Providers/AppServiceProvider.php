<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\LLMClient;
use App\Services\AIClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LLMClient::class, AIClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
