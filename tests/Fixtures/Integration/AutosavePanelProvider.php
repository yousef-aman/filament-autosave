<?php

namespace YousefAman\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Panel;
use Filament\PanelProvider;
use YousefAman\FilamentAutosave\AutosavePlugin;

class AutosavePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->resources([PostResource::class])
            ->plugins([AutosavePlugin::make()]);
    }
}
