<?php

namespace JeffersonGoncalves\ImageCache\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \JeffersonGoncalves\ImageCache\ImageCache
 */
class ImageCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'laravel-image-cache';
    }
}
