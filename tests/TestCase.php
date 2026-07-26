<?php

namespace YousefAman\FilamentAutosave\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Livewire\LivewireServiceProvider;
use Livewire\Mechanisms\DataStore;
use Orchestra\Testbench\TestCase as BaseTestCase;
use YousefAman\FilamentAutosave\AutosaveServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Outside an HTTP request the `errors` view variable is never shared
        // (normally done by the ShareErrorsFromSession middleware). Livewire's
        // validation render hook dereferences it, so seed an empty bag.
        View::share('errors', new ViewErrorBag);

        // In this bare container DataStore isn't left shared, so every store()
        // call gets a fresh WeakMap and the error bag reads back null mid-render.
        $this->app->instance(DataStore::class, new DataStore);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function getPackageProviders($app): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            SchemasServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            AutosaveServiceProvider::class,
        ];
    }
}
