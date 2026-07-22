<?php

namespace YousefAman\FilamentAutosave\Tests;

use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Livewire\LivewireServiceProvider;
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
    }

    protected function getPackageProviders($app): array
    {
        return [
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
