<?php

namespace YousefAman\FilamentAutosave;

use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AutosaveServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-autosave';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations();
    }

    public function packageBooted(): void
    {
        FilamentAsset::register([
            Css::make(static::$name, __DIR__.'/../resources/css/autosave.css'),
            AlpineComponent::make('autosave', __DIR__.'/../resources/js/autosave.js'),
        ], package: 'yousefaman/filament-autosave');
    }
}
