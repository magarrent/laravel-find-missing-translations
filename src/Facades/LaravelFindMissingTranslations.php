<?php

namespace Magarrent\LaravelFindMissingTranslations\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Magarrent\LaravelFindMissingTranslations\LaravelFindMissingTranslations
 */
class LaravelFindMissingTranslations extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Magarrent\LaravelFindMissingTranslations\LaravelFindMissingTranslations::class;
    }
}
