<?php

namespace JeffersonGoncalves\ImageCache\Tests;

use JeffersonGoncalves\ImageCache\ImageCacheServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ImageCacheServiceProvider::class,
        ];
    }
}
