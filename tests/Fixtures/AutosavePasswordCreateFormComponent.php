<?php

namespace YousefAman\FilamentAutosave\Tests\Fixtures;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;
use YousefAman\FilamentAutosave\HasAutosaveForCreate;

/** Password field named so the config `except` list can't be what protects it. */
class AutosavePasswordCreateFormComponent extends Component implements HasSchemas
{
    use HasAutosaveForCreate;
    use InteractsWithSchemas;

    public ?array $data = [];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title'),
                TextInput::make('vault_key')
                    ->password()
                    ->dehydrated(false),
            ])
            ->statePath('data');
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div></div>
        BLADE;
    }
}
