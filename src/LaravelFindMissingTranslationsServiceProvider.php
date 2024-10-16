<?php

namespace Magarrent\LaravelFindMissingTranslations;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Magarrent\LaravelFindMissingTranslations\Commands\LaravelFindMissingTranslationsCommand;

class LaravelFindMissingTranslationsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-find-missing-translations')
            ->hasCommand(LaravelFindMissingTranslationsCommand::class);
    }
}
