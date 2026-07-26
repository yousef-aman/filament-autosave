<?php

namespace YousefAman\FilamentAutosave\Tests;

use Filament\Facades\Filament;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Support\Facades\Schema;
use YousefAman\FilamentAutosave\Tests\Fixtures\Integration\AutosavePanelProvider;

/** Boots a real panel so the traits run on genuine resource pages and a real database. */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function ($table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->nullable();
            $table->text('settings')->nullable();
        });

        Filament::setCurrentPanel('admin');
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            TablesServiceProvider::class,
            NotificationsServiceProvider::class,
            WidgetsServiceProvider::class,
            InfolistsServiceProvider::class,
            AutosavePanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
