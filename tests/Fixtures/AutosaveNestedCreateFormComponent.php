<?php

namespace YousefAman\FilamentAutosave\Tests\Fixtures;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;
use YousefAman\FilamentAutosave\HasAutosaveForCreate;

/** Create page with a password nested under a Group state path. */
class AutosaveNestedCreateFormComponent extends Component implements HasSchemas
{
    use HasAutosaveForCreate;
    use InteractsWithSchemas;

    public ?array $data = [];

    public function mount(): void
    {
        $this->mountHasAutosaveForCreate();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title'),
                Group::make([
                    TextInput::make('name'),
                    TextInput::make('api_key')->password(),
                ])->statePath('settings'),
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
