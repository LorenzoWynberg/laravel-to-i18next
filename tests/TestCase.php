<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use OznerOmali\LaravelToI18nextLangParser\Providers\LaravelToI18nextLangParserServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LaravelToI18nextLangParserServiceProvider::class];
    }
}
