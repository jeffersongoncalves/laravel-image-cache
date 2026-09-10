<?php

declare(strict_types=1);

namespace JeffersonGoncalves\ImageCache\Tests;

use JeffersonGoncalves\ImageCache\ImageCacheServiceProvider;
use JeffersonGoncalves\SsrfGuard\SsrfGuardServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            SsrfGuardServiceProvider::class,
            ImageCacheServiceProvider::class,
        ];
    }
}
