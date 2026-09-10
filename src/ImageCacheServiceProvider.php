<?php

namespace JeffersonGoncalves\ImageCache;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ImageCacheServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-image-cache')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigrations();
    }
}
