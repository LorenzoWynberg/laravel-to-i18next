<?php

namespace OznerOmali\LaravelToI18nextLangParser\Providers;

use Illuminate\Support\ServiceProvider;
use OznerOmali\LaravelToI18nextLangParser\Console\ParseLangToI18NextCommand;

class LaravelToI18nextLangParserServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ParseLangToI18NextCommand::class,
            ]);
        }
    }

    public function register(): void {}
}
