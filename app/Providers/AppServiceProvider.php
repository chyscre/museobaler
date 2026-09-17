<?php

namespace App\Providers;

use App\Services\Gemini;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The AI behind the exhibit form; built from config/services.php.
        $this->app->bind(Gemini::class, fn () => Gemini::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
