<?php

declare(strict_types=1);

namespace OznerOmali\LaravelToI18nextLangParser\Providers;

use Illuminate\Support\ServiceProvider;
use OznerOmali\LaravelToI18nextLangParser\Console\ParseLangToI18NextCommand;

class LaravelToI18nextLangParserServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/laravel-to-i18next.php', 'laravel-to-i18next');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/laravel-to-i18next.php' => config_path('laravel-to-i18next.php'),
            ], 'laravel-to-i18next-config');

            $this->commands([
                ParseLangToI18NextCommand::class,
            ]);
        }
    }
}
